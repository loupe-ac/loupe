package cmd

import (
	"context"
	"errors"
	"slices"
	"strings"
	"sync"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/event"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

const runCommandRules = `
accounts:
  claude:
    harness: claude-code
defaults:
  account: claude
projects:
  loupe:
    dir: {dir}
work:
  export:
    action: command
    params:
      out: { pattern: "[a-z -]+" }
    run: [export, "--out={out}"]
  note:
    action: command
    run: [note, "{cardNumber}"]
  plan:
    prompt: Plan card {cardNumber}.
`

const inboxItemID = "0199b000-0000-7000-8000-00000000aaaa"

// runCommandRequest is an open run-command request about an inbox item.
func runCommandRequest(n int, kind, branch string, params map[string]string) api.WorkRequest {
	w := workRequest(n, 0, kind, api.WorkRequestOpen)
	w.SubjectType, w.SubjectID, w.RuleID = "inbox-item", inboxItemID, "inbox:run-command"
	w.Capability, w.Branch, w.Params = rules.CapabilityRunCommand, branch, params

	return w
}

// fakeWorktrees answers `git worktree list` with a fixed list and records the
// folders it was asked about.
type fakeWorktrees struct {
	mu    sync.Mutex
	list  []worktree
	err   error
	dirs  []string
	calls int
}

func (f *fakeWorktrees) read(_ context.Context, dir string) ([]worktree, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.calls++
	f.dirs = append(f.dirs, dir)

	return f.list, f.err
}

func withRunCommand(t *testing.T) (*harness, *fakeWork, *fakeCommand, *fakeWorktrees, *stateRecorder) {
	t.Helper()
	h := newHarnessWith(t, runCommandRules, rules.Defaults{})
	rec := h.states()
	f := h.withWork()
	cmd := &fakeCommand{}
	h.router.worker.command = cmd.run
	wt := &fakeWorktrees{list: []worktree{{Branch: "main", Path: "/wt/main"}, {Branch: "epic/1", Path: "/wt/epic one"}}}
	h.router.worktrees = wt.read

	return h, f, cmd, wt, rec
}

// A run-command request about an inbox item runs the named entry in the folder
// of its branch, and the run reports as a command.
func TestARunCommandRequestRunsTheEntryInTheFolderOfItsBranch(t *testing.T) {
	for branch, want := range map[string]string{"": "/wt/main", "main": "/wt/main", "epic/1": "/wt/epic one"} {
		h, f, cmd, wt, rec := withRunCommand(t)
		cmd.result = procResult{output: "done"}

		w := runCommandRequest(1, "export", branch, map[string]string{"out": "my folder"})
		w.Run = []string{"export", "--out={out}"}

		h.offer(f, w)

		specs := cmd.recorded()
		if len(specs) != 1 || specs[0].dir != want {
			t.Fatalf("branch %q: commands = %+v, want dir %s", branch, specs, want)
		}
		// A value with spaces stays one argument.
		if !slices.Equal(specs[0].argv, []string{"export", "--out=my folder"}) {
			t.Fatalf("argv = %q", specs[0].argv)
		}
		if !slices.Contains(wt.dirs, h.dir) {
			t.Fatalf("worktrees read in %v, want the project dir %s", wt.dirs, h.dir)
		}
		sent := rec.states()
		wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
		wantCommandReports(t, sent)
		if r := sent[0].report; r.SubjectType != "inbox-item" || r.SubjectID != inboxItemID || r.CardNumber != 0 || r.WorkKind != "export" {
			t.Fatalf("report = %+v", r)
		}
		if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " done"}) {
			t.Fatalf("results = %v", got)
		}
	}
}

// A request that fails a check settles as refused with a reason code. It runs
// nothing and reports no worker run.
func TestARunCommandRequestIsRefusedWithAReasonCode(t *testing.T) {
	for name, tc := range map[string]struct {
		kind, branch string
		params       map[string]string
		reason       string
		run          []string
	}{
		"unknown entry":        {"nope", "", nil, "entry-missing", nil},
		"agent entry":          {"plan", "", nil, "entry-missing", nil},
		"missing param":        {"export", "", nil, "param-invalid", nil},
		"undeclared param":     {"export", "", map[string]string{"out": "ok", "extra": "x"}, "param-invalid", nil},
		"option as value":      {"export", "", map[string]string{"out": "--exec=evil"}, "param-invalid", nil},
		"trailing newline":     {"export", "", map[string]string{"out": "ok\n"}, "param-invalid", nil},
		"branch has no folder": {"export", "nope", map[string]string{"out": "ok"}, "worktree-missing", nil},
		"card value, no card":  {"note", "", nil, "placeholder-missing", nil},
		"run list changed":     {"export", "", map[string]string{"out": "ok"}, "entry-changed", []string{"export", "--dry-run", "--out={out}"}},
	} {
		t.Run(name, func(t *testing.T) {
			h, f, cmd, _, rec := withRunCommand(t)
			w := runCommandRequest(1, tc.kind, tc.branch, tc.params)
			w.Run = tc.run

			h.offer(f, w)

			if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " refused " + tc.reason}) {
				t.Fatalf("results = %v, want refused %s", got, tc.reason)
			}
			if specs := cmd.recorded(); len(specs) != 0 {
				t.Fatalf("commands = %+v", specs)
			}
			if sent := rec.states(); len(sent) != 0 {
				t.Fatalf("reports = %+v, want none", sent)
			}
			if got := h.heldClaims(); len(got) != 0 {
				t.Fatalf("claims after the result = %v", got)
			}
		})
	}
}

// A folder that vanishes while the run waits ends the run as failed and runs
// nothing.
func TestARunCommandRequestReadsTheFolderAgainBeforeItRuns(t *testing.T) {
	h, f, cmd, wt, rec := withRunCommand(t)
	h.router.worktrees = func(ctx context.Context, dir string) ([]worktree, error) {
		list, err := wt.read(ctx, dir)
		if wt.calls > 1 {
			return nil, err
		}

		return list, err
	}

	h.offer(f, runCommandRequest(1, "export", "", map[string]string{"out": "ok"}))

	if specs := cmd.recorded(); len(specs) != 0 {
		t.Fatalf("commands = %+v", specs)
	}
	wantStates(t, rec.states(), api.RunQueued, api.RunFailed)
	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " refused failed"}) {
		t.Fatalf("results = %v", got)
	}
}

// A git failure leaves no folder, so the request is refused.
func TestARunCommandRequestIsRefusedWhenGitFails(t *testing.T) {
	h, f, cmd, wt, _ := withRunCommand(t)
	wt.err = errors.New("not a git repository")

	h.offer(f, runCommandRequest(1, "export", "", map[string]string{"out": "ok"}))

	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " refused worktree-missing"}) {
		t.Fatalf("results = %v", got)
	}
	if len(cmd.recorded()) != 0 {
		t.Fatal("a command ran")
	}
}

// The branch and the params decode from the JSON of a work request. An empty
// list, which a PHP server writes for an empty map, and an absent key decode
// as none.
func TestAWorkRequestDecodesBranchAndParams(t *testing.T) {
	base := workPayload(runCommandRequest(1, "export", "", nil))
	for name, tc := range map[string]struct {
		extra  string
		branch string
		params map[string]string
	}{
		"absent":     {``, "", nil},
		"empty list": {`,"params":[]`, "", nil},
		"null":       {`,"params":null`, "", nil},
		"values":     {`,"branch":"epic/1","params":{"out":"a b"},"run":["export","--out={out}"]`, "epic/1", map[string]string{"out": "a b"}},
	} {
		w, err := event.ParseWorkRequest([]byte(strings.TrimSuffix(base, "}") + tc.extra + "}"))
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		wantRun := []string(nil)
		if tc.branch != "" {
			wantRun = []string{"export", "--out={out}"}
		}
		if w.Branch != tc.branch || len(w.Params) != len(tc.params) || w.Params["out"] != tc.params["out"] || !slices.Equal(w.Run, wantRun) {
			t.Fatalf("%s: branch %q params %v", name, w.Branch, w.Params)
		}
	}
	if (api.WorkRequest{}).RunBranch() != "main" {
		t.Fatal("RunBranch default is not main")
	}
}

// A run-command run streams its output while it runs. The last chunk reaches
// Loupe before the run reports succeeded, so the stored log is complete when the
// item shows it.
func TestARunCommandRunStreamsItsOutputBeforeItSucceeds(t *testing.T) {
	h, f, cmd, _, rec := withRunCommand(t)
	cmd.dir, cmd.stdout = t.TempDir(), "one\ntwo\nthree\n"
	cmd.result = procResult{output: "done"}

	h.offer(f, runCommandRequest(1, "export", "", map[string]string{"out": "ok"}))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	runID := sent[0].runID
	if got := rec.output(runID); got != cmd.stdout {
		t.Fatalf("stored output = %q, want %q", got, cmd.stdout)
	}
	names := rec.names()
	succeeded := slices.Index(names, api.RunSucceeded)
	if slices.Index(names, "output") < slices.Index(names, api.RunRunning) {
		t.Fatalf("order = %v, want output after running", names)
	}
	if slices.Contains(names[succeeded:], "output") {
		t.Fatalf("order = %v, want no output after succeeded", names)
	}
	rec.mu.Lock()
	defer rec.mu.Unlock()
	for _, v := range rec.sent {
		if o, ok := v.(outputSent); ok && (o.handle != testProject || o.runID != runID || o.bridgeID != testBridgeID) {
			t.Fatalf("chunk = %+v", o)
		}
	}
}

// A run-command run asks for merged streams, and a command rule does not
// ask for them. A command rule streams nothing either.
func TestOnlyARunCommandRunMergesAndStreams(t *testing.T) {
	h, f, cmd, _, rec := withRunCommand(t)
	cmd.dir, cmd.stdout = t.TempDir(), "out\n"
	h.offer(f, runCommandRequest(1, "export", "", map[string]string{"out": "ok"}))
	if specs := cmd.recorded(); len(specs) != 1 || !specs[0].merge {
		t.Fatalf("run-command specs = %+v, want merge", specs)
	}

	rh, rule := withCommand(t, "1m")
	rrec := rh.states()
	rule.dir, rule.stdout = t.TempDir(), "out\n"
	rh.send(doneMoved(87))
	if specs := rule.recorded(); len(specs) != 1 || specs[0].merge {
		t.Fatalf("rule command specs = %+v, want no merge", specs)
	}
	if slices.Contains(rrec.names(), "output") {
		t.Fatalf("a command rule streamed: %v", rrec.names())
	}
	if !slices.Contains(rec.names(), "output") {
		t.Fatalf("a run-command run streamed nothing: %v", rec.names())
	}
}
