package cmd

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

const itemSessionRules = `
accounts:
  claude:
    harness: claude-code
defaults:
  account: claude
projects:
  loupe:
    dir: {dir}
launch:
  command: {launcher}
work:
  session:
    action: interactive
    prompt: Work with the owner in project {project}.
`

const itemSessionID = "0199b000-0000-7000-8000-00000000bbbb"

// itemSessionRequest is an open session request about inbox item 12, which
// holds no single card.
func itemSessionRequest(n int) api.WorkRequest {
	w := workRequest(n, 0, "session", api.WorkRequestOpen)
	w.SubjectType, w.SubjectID, w.RuleID = api.SubjectInboxItem, inboxItemID, "person.start-session"
	w.Capability = rules.CapabilitySession
	w.Context.InboxActionID, w.Context.InboxItemNumber, w.Context.InboxItemID = itemSessionID, 12, inboxItemID

	return w
}

func itemHarness(t *testing.T, launcher string) (*harness, *fakeWork, *stateRecorder) {
	t.Helper()
	isolateConfig(t)
	h, rec := launchHarnessWith(t, itemSessionRules, launcher)
	newRepo(t, h.dir)
	h.router.items = newItemSessions(h.router.log, func(context.Context, []string) []api.InteractiveSessionReading { return nil })

	return h, h.withWork(), rec
}

func TestAnInboxItemSessionOpensInAPlainWorktree(t *testing.T) {
	out := filepath.Join(t.TempDir(), "argv")
	h, f, rec := itemHarness(t, `[sh, -c, 'printf "[%s]" "$1" > "$0"', '`+out+`', '{cardNumber}', '{script}']`)

	h.offer(f, itemSessionRequest(1))

	want, _ := config.ItemWorktreeDir("loupe", 12)
	if got, err := os.ReadFile(out); err != nil || string(got) != "[]" {
		t.Fatalf("argv = %q (%v), want an empty card number", got, err)
	}
	if branch := runGit(t, want, "symbolic-ref", "--short", "HEAD"); branch != "inbox-12" {
		t.Fatalf("branch = %q", branch)
	}
	sent := rec.launches()
	if len(sent) != 1 {
		t.Fatalf("launches = %+v", sent)
	}
	r := sent[0].report
	if r.State != api.RunRunning || r.SubjectType != api.SubjectInboxItem || r.SubjectID != inboxItemID || r.CardNumber != 0 || r.WorkKind != "session" || r.RuleID != "person.start-session" || r.WorkRequestID != workID(1) {
		t.Fatalf("report = %+v", r)
	}
	script, _ := os.ReadFile(filepath.Join(h.router.scriptDir, testSession+".sh"))
	if !strings.Contains(string(script), "cd -- '"+want+"'") {
		t.Fatalf("script = %q, want it to start in %s", script, want)
	}
}

func TestAFailedItemLaunchRemovesTheWorktreeAndTheBranch(t *testing.T) {
	h, f, rec := itemHarness(t, `[sh, -c, 'exit 3', sh, '{script}']`)

	h.offer(f, itemSessionRequest(1))

	path, _ := config.ItemWorktreeDir("loupe", 12)
	if exists(path) || exists(path+".session") || hasBranch(t, h.dir, "inbox-12") {
		t.Fatal("a failed launch left the worktree, the record or the branch")
	}
	sent := rec.launches()
	if len(sent) != 1 || sent[0].report.State != api.RunNotStarted || sent[0].report.SubjectType != api.SubjectInboxItem || sent[0].report.CardNumber != 0 {
		t.Fatalf("launches = %+v", sent)
	}
}

func TestAnItemLaunchFailsWithAReasonWhenTheWorktreeCannotBeMade(t *testing.T) {
	h, f, rec := itemHarness(t, `[sh, -c, 'exit 0', sh, '{script}']`)
	runGit(t, h.dir, "branch", "inbox-12")

	h.offer(f, itemSessionRequest(1))

	sent := rec.launches()
	if len(sent) != 1 || sent[0].report.State != api.RunNotStarted || !strings.Contains(sent[0].report.FailureReason, "inbox-12") {
		t.Fatalf("launches = %+v", sent)
	}
	if got := h.scripts(t); len(got) != 0 {
		t.Fatalf("scripts = %v", got)
	}
}

func TestASecondItemLaunchFailsWhileTheFirstSessionRuns(t *testing.T) {
	h, f, rec := itemHarness(t, `[sh, -c, 'exit 0', sh, '{script}']`)
	alive := true
	h.router.items.readings = func(_ context.Context, ids []string) []api.InteractiveSessionReading {
		return []api.InteractiveSessionReading{{SessionID: ids[0], Alive: &alive}}
	}

	h.offer(f, itemSessionRequest(1))
	h.offer(f, itemSessionRequest(2))

	sent := rec.launches()
	if len(sent) != 2 || sent[0].report.State != api.RunRunning || sent[1].report.State != api.RunNotStarted || !strings.Contains(sent[1].report.FailureReason, "item 12") {
		t.Fatalf("launches = %+v", sent)
	}
	path, _ := config.ItemWorktreeDir("loupe", 12)
	if !exists(path) {
		t.Fatal("the worktree of the running session is gone")
	}
}

func TestACardSessionRequestStillOpensInTheProjectFolder(t *testing.T) {
	h, f, rec := itemHarness(t, `[sh, -c, 'exit 0', sh, '{script}']`)

	h.offer(f, workRequest(1, 87, "session", api.WorkRequestOpen))

	sent := rec.launches()
	if len(sent) != 1 || sent[0].report.SubjectType != api.SubjectCard || sent[0].report.CardNumber != 87 {
		t.Fatalf("launches = %+v", sent)
	}
	path, _ := config.ItemWorktreeDir("loupe", 87)
	if exists(path) {
		t.Fatal("a card session made an item worktree")
	}
}
