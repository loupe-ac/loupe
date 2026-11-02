package cmd

import (
	"bytes"
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
)

const (
	remindProject = "0192f3c4-5d6e-7f80-9123-456789abcdef"
	remindOther   = "0192f3c4-5d6e-7f80-9123-456789abcd00"
	editInput     = `{"session_id":"sess-1","tool_name":"Edit","tool_input":{"file_path":"a.php"}}`
	prInput       = `{"session_id":"sess-1","tool_name":"Bash","tool_input":{"command":"gh pr create --draft"}}`
)

type remindEnv struct {
	t     *testing.T
	state string
	now   time.Time
	calls []string
	card  api.CardRun
	err   error
}

// newRemindEnv works in a folder that .loupe.yaml links to remindProject.
func newRemindEnv(t *testing.T) *remindEnv {
	t.Helper()
	dir := t.TempDir()
	if err := projectfile.Write(dir, remindProject); err != nil {
		t.Fatal(err)
	}
	t.Chdir(dir)

	e := &remindEnv{t: t, state: filepath.Join(t.TempDir(), "state"), now: time.Unix(1_700_000_000, 0)}
	oldNow, oldFetch, oldDir := remindNow, fetchCardRun, remindStateDir
	remindNow = func() time.Time { return e.now }
	remindStateDir = func() (string, error) { return e.state, nil }
	fetchCardRun = func(_ context.Context, sessionID, project string) (api.CardRun, error) {
		e.calls = append(e.calls, sessionID+"@"+project)

		return e.card, e.err
	}
	t.Cleanup(func() { remindNow, fetchCardRun, remindStateDir = oldNow, oldFetch, oldDir })

	return e
}

func (e *remindEnv) run(stdin string) string {
	e.t.Helper()
	cmd := newSessionCmd()
	var out, errOut bytes.Buffer
	cmd.SetOut(&out)
	cmd.SetErr(&errOut)
	cmd.SetIn(strings.NewReader(stdin))
	cmd.SetArgs([]string{"remind"})
	if err := cmd.Execute(); err != nil {
		e.t.Fatalf("the command must not fail: %v", err)
	}
	if errOut.Len() != 0 {
		e.t.Fatalf("stderr = %q", errOut.String())
	}

	return out.String()
}

func (e *remindEnv) wantReminder(out string) {
	e.t.Helper()
	want := `{"hookSpecificOutput":{"additionalContext":` + jsonString(remindText) + `,"hookEventName":"PreToolUse"}}` + "\n"
	if out != want {
		e.t.Fatalf("output = %q, want %q", out, want)
	}
}

func jsonString(s string) string {
	return `"` + strings.NewReplacer(`"`, `\"`).Replace(s) + `"`
}

func TestRemindWhenLifecycleHasNoOpenRun(t *testing.T) {
	e := newRemindEnv(t)
	e.card = api.CardRun{Template: "lifecycle"}

	e.wantReminder(e.run(editInput))
	if len(e.calls) != 1 || e.calls[0] != "sess-1@"+remindProject {
		t.Fatalf("calls = %v", e.calls)
	}
}

func TestRemindStaysSilentOnAnswersThatNeedNoReminder(t *testing.T) {
	tests := []struct {
		name string
		card api.CardRun
		err  error
	}{
		{"open run", api.CardRun{Template: "lifecycle", Open: true}, nil},
		{"other template", api.CardRun{Template: "kanban"}, nil},
		{"no template", api.CardRun{}, nil},
		{"request error", api.CardRun{}, errors.New("HTTP 500")},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			e := newRemindEnv(t)
			e.card, e.err = tc.card, tc.err
			if out := e.run(editInput); out != "" {
				t.Fatalf("output = %q", out)
			}
		})
	}
}

func TestRemindIgnoresBadInput(t *testing.T) {
	for _, in := range []string{
		``, `not json`, `{}`, `{"session_id":5}`, `{"session_id":"../x","tool_name":"Edit"}`,
		`{"session_id":"s","tool_name":"Bash","tool_input":{"command":"git status"}}`,
		`{"session_id":"s","tool_name":"Bash"}`,
	} {
		e := newRemindEnv(t)
		e.card = api.CardRun{Template: "lifecycle"}
		if out := e.run(in); out != "" || len(e.calls) != 0 {
			t.Errorf("input %q: output = %q, calls = %v", in, out, e.calls)
		}
	}
}

func TestRemindNeedsAnExplicitProject(t *testing.T) {
	e := newRemindEnv(t)
	e.card = api.CardRun{Template: "lifecycle"}
	if err := os.Remove(filepath.Join(mustGetwd(t), projectfile.Name)); err != nil {
		t.Fatal(err)
	}
	if out := e.run(editInput); out != "" || len(e.calls) != 0 {
		t.Fatalf("no file: output = %q, calls = %v", out, e.calls)
	}

	if err := os.WriteFile(projectfile.Name, []byte("project: nope\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if out := e.run(editInput); out != "" || len(e.calls) != 0 {
		t.Fatalf("bad file: output = %q, calls = %v", out, e.calls)
	}

	if err := os.WriteFile(projectfile.Name, []byte("other: 1\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if out := e.run(editInput); out != "" || len(e.calls) != 0 {
		t.Fatalf("file without project: output = %q, calls = %v", out, e.calls)
	}
}

func TestRemindSetupLinkWinsOverTheProjectFile(t *testing.T) {
	e := newRemindEnv(t)
	e.card = api.CardRun{Template: "lifecycle"}
	setup := t.TempDir()
	t.Setenv("LOUPE_CONFIG", setup)
	if err := projectfile.WriteLink(setup, projectfile.LinkKey(mustGetwd(t)), remindOther); err != nil {
		t.Fatal(err)
	}

	e.wantReminder(e.run(editInput))
	if len(e.calls) != 1 || e.calls[0] != "sess-1@"+remindOther {
		t.Fatalf("calls = %v", e.calls)
	}
}

func TestRemindSilencesTheSessionForTenMinutes(t *testing.T) {
	tests := []struct {
		name  string
		card  api.CardRun
		first string
	}{
		{"after a reminder", api.CardRun{Template: "lifecycle"}, "reminder"},
		{"after an open run", api.CardRun{Template: "lifecycle", Open: true}, ""},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			e := newRemindEnv(t)
			e.card = tc.card
			if got := e.run(editInput); (got != "") != (tc.first != "") {
				t.Fatalf("first output = %q", got)
			}

			e.now = e.now.Add(9*time.Minute + 59*time.Second)
			if got := e.run(editInput); got != "" || len(e.calls) != 1 {
				t.Fatalf("inside the window: output = %q, calls = %v", got, e.calls)
			}

			e.now = e.now.Add(2 * time.Second)
			e.card = api.CardRun{Template: "lifecycle"}
			e.wantReminder(e.run(editInput))
			if len(e.calls) != 2 {
				t.Fatalf("after the window: calls = %v", e.calls)
			}
		})
	}
}

func TestRemindPullRequestCreateChecksAgain(t *testing.T) {
	e := newRemindEnv(t)
	e.card = api.CardRun{Template: "lifecycle", Open: true}
	e.run(editInput)

	e.card = api.CardRun{Template: "lifecycle"}
	if got := e.run(editInput); got != "" {
		t.Fatalf("an edit inside the window gave %q", got)
	}
	e.wantReminder(e.run(prInput))
	if len(e.calls) != 2 {
		t.Fatalf("calls = %v", e.calls)
	}
}

func TestRemindPullRequestCommandForms(t *testing.T) {
	for _, command := range []string{
		`gh pr create`, `cd x && gh pr create --fill`, `gh  pr   create -t "a"`, "git push\ngh pr create",
	} {
		e := newRemindEnv(t)
		e.card = api.CardRun{Template: "lifecycle"}
		in := `{"session_id":"s","tool_name":"Bash","tool_input":{"command":` + jsonString(strings.ReplaceAll(command, "\n", `\n`)) + `}}`
		e.wantReminder(e.run(in))
	}
}

func TestRemindOtherTemplateSilencesForGood(t *testing.T) {
	e := newRemindEnv(t)
	e.card = api.CardRun{Template: "kanban"}
	e.run(editInput)

	e.now = e.now.Add(24 * time.Hour)
	e.card = api.CardRun{Template: "lifecycle"}
	if got := e.run(editInput); got != "" {
		t.Fatalf("edit: output = %q", got)
	}
	if got := e.run(prInput); got != "" {
		t.Fatalf("pull request: output = %q", got)
	}
	if len(e.calls) != 1 {
		t.Fatalf("calls = %v", e.calls)
	}
}

func TestRemindStateBelongsToTheProject(t *testing.T) {
	e := newRemindEnv(t)
	e.card = api.CardRun{Template: "kanban"}
	e.run(editInput)

	if err := projectfile.Write(mustGetwd(t), remindOther); err != nil {
		t.Fatal(err)
	}
	e.card = api.CardRun{Template: "lifecycle"}
	e.wantReminder(e.run(editInput))
}

func TestRemindNoTemplateRechecksAfterTheWindow(t *testing.T) {
	e := newRemindEnv(t)
	e.run(editInput)

	e.now = e.now.Add(11 * time.Minute)
	e.card = api.CardRun{Template: "lifecycle"}
	e.wantReminder(e.run(editInput))
}

func TestRemindWritesAPrivateStateFile(t *testing.T) {
	e := newRemindEnv(t)
	e.card = api.CardRun{Template: "lifecycle"}
	e.run(editInput)

	info, err := os.Stat(filepath.Join(e.state, "sess-1.json"))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("mode = %v", info.Mode().Perm())
	}
	entries, _ := os.ReadDir(e.state)
	if len(entries) != 1 {
		t.Fatalf("leftover files: %v", entries)
	}
}

func TestRemindCorruptStateIsIgnored(t *testing.T) {
	e := newRemindEnv(t)
	if err := os.MkdirAll(e.state, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(e.state, "sess-1.json"), []byte("{broken"), 0o600); err != nil {
		t.Fatal(err)
	}
	e.card = api.CardRun{Template: "lifecycle"}
	e.wantReminder(e.run(editInput))
}

func TestRemindTimeoutIsTwoSeconds(t *testing.T) {
	e := newRemindEnv(t)
	var deadline time.Duration
	fetchCardRun = func(ctx context.Context, _, _ string) (api.CardRun, error) {
		d, ok := ctx.Deadline()
		if !ok {
			t.Error("no deadline")
		}
		deadline = time.Until(d)

		return api.CardRun{}, errors.New("slow")
	}
	e.run(editInput)
	if deadline <= time.Second || deadline > remindTimeout {
		t.Fatalf("deadline = %v", deadline)
	}
}

func mustGetwd(t *testing.T) string {
	t.Helper()
	d, err := os.Getwd()
	if err != nil {
		t.Fatal(err)
	}

	return d
}

func TestRemindTextTellsDesignSessionsToIgnoreIt(t *testing.T) {
	for _, want := range []string{"product design or tech design session ignores this reminder", "never moves its card to Implementation"} {
		if !strings.Contains(remindText, want) {
			t.Fatalf("remindText lacks %q: %s", want, remindText)
		}
	}
}
