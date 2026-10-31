package cmd

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

const itemSession = "0199a0e2-aaaa-7c5e-9f2a-3b1c6d7e8f90"

// itemFixture is a project repository with an isolated config folder, and a
// tracker whose liveness readings the test sets.
type itemFixture struct {
	t       *testing.T
	project string
	items   *itemSessions
	alive   *bool
	now     time.Time
}

func newItemFixture(t *testing.T) *itemFixture {
	t.Helper()
	isolateConfig(t)
	project := t.TempDir()
	newRepo(t, project)
	f := &itemFixture{t: t, project: project, now: time.Date(2026, 10, 30, 12, 0, 0, 0, time.UTC)}
	f.items = newItemSessions(nil, nil)
	f.items.now = func() time.Time { return f.now }
	f.items.readings = func(_ context.Context, ids []string) []api.InteractiveSessionReading {
		out := make([]api.InteractiveSessionReading, len(ids))
		for i, id := range ids {
			out[i] = api.InteractiveSessionReading{SessionID: id, Alive: f.alive}
		}

		return out
	}

	return f
}

func (f *itemFixture) setAlive(v *bool) { f.alive = v }

func (f *itemFixture) prepare(item int) (string, error) {
	return f.items.prepare(context.Background(), f.project, "loupe", item, itemSession)
}

func hasBranch(t *testing.T, dir, branch string) bool {
	t.Helper()
	cmd := exec.Command("git", "rev-parse", "--verify", "--quiet", "refs/heads/"+branch)
	cmd.Dir = dir

	return cmd.Run() == nil
}

func TestPrepareMakesAPlainWorktreeOnANewBranch(t *testing.T) {
	f := newItemFixture(t)

	got, err := f.prepare(12)
	if err != nil {
		t.Fatal(err)
	}

	want, _ := config.ItemWorktreeDir("loupe", 12)
	if got != want || filepath.Base(got) != "item-12" {
		t.Fatalf("worktree = %s, want %s", got, want)
	}
	if branch := runGit(t, got, "symbolic-ref", "--short", "HEAD"); branch != "inbox-12" {
		t.Fatalf("branch = %q", branch)
	}
	if runGit(t, got, "rev-parse", "HEAD") != runGit(t, f.project, "rev-parse", "main") {
		t.Fatal("the branch does not start at the default branch")
	}
	if !exists(got + ".session") {
		t.Fatal("no session record next to the worktree")
	}
}

func TestPrepareRefusesWhileTheSessionOfTheItemRuns(t *testing.T) {
	f := newItemFixture(t)
	dir, err := f.prepare(12)
	if err != nil {
		t.Fatal(err)
	}
	f.items.finish(dir)
	f.now = f.now.Add(time.Hour)

	for name, alive := range map[string]*bool{"alive": ptr(true), "unknown": nil} {
		f.setAlive(alive)
		if _, err := f.prepare(12); err == nil || !strings.Contains(err.Error(), "item 12") {
			t.Fatalf("%s: err = %v", name, err)
		}
		if !exists(dir) {
			t.Fatalf("%s: the worktree of a live session was removed", name)
		}
	}
}

func TestPrepareRefusesJustAfterALaunch(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.finish(dir)
	f.setAlive(ptr(false))

	if _, err := f.prepare(12); err == nil {
		t.Fatal("a launch seconds ago counts as running")
	}
}

func TestPrepareReplacesTheStaleWorktreeOfAnEndedSession(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.finish(dir)
	if err := os.WriteFile(filepath.Join(dir, "unsaved.txt"), []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}
	f.now = f.now.Add(time.Hour)
	f.setAlive(ptr(false))

	if _, err := f.prepare(12); err == nil || !exists(filepath.Join(dir, "unsaved.txt")) {
		t.Fatalf("one dead reading replaced the worktree: err = %v", err)
	}
	got, err := f.prepare(12)
	if err != nil {
		t.Fatal(err)
	}
	if got != dir || exists(filepath.Join(got, "unsaved.txt")) {
		t.Fatalf("worktree = %s, stale file kept = %v", got, exists(filepath.Join(got, "unsaved.txt")))
	}
}

func TestPrepareLeavesNothingWhenTheBranchExists(t *testing.T) {
	f := newItemFixture(t)
	runGit(t, f.project, "branch", "inbox-12")

	if _, err := f.prepare(12); err == nil || !strings.Contains(err.Error(), "inbox-12") {
		t.Fatalf("err = %v", err)
	}

	path, _ := config.ItemWorktreeDir("loupe", 12)
	if exists(path) || exists(path+".session") || !hasBranch(t, f.project, "inbox-12") {
		t.Fatal("a failed prepare must leave no folder, no record, and the old branch")
	}
}

func TestPrepareLeavesNothingWhenGitFails(t *testing.T) {
	f := newItemFixture(t)

	if _, err := f.items.prepare(context.Background(), t.TempDir(), "loupe", 12, itemSession); err == nil {
		t.Fatal("a folder that is no repository must fail")
	}

	path, _ := config.ItemWorktreeDir("loupe", 12)
	if exists(path) || exists(path+".session") {
		t.Fatal("a failed prepare must leave nothing")
	}
}

func TestDiscardRemovesTheWorktreeAndTheBranch(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)

	f.items.discard(context.Background(), dir)

	if exists(dir) || exists(dir+".session") || hasBranch(t, f.project, "inbox-12") {
		t.Fatal("discard left the worktree, the record or the branch")
	}
	if got := worktreePaths(t, f.project); len(got) != 1 {
		t.Fatalf("worktrees = %v", got)
	}
}

func TestSweepRemovesAfterTwoReadingsOfADeadSession(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.finish(dir)
	f.now = f.now.Add(time.Hour)
	ctx := context.Background()

	f.setAlive(ptr(false))
	f.items.sweep(ctx)
	if !exists(dir) {
		t.Fatal("one dead reading removed the worktree")
	}
	f.setAlive(ptr(true))
	f.items.sweep(ctx)
	f.setAlive(ptr(false))
	f.items.sweep(ctx)
	if !exists(dir) {
		t.Fatal("an alive reading must reset the count")
	}
	f.items.sweep(ctx)
	if exists(dir) || hasBranch(t, f.project, "inbox-12") {
		t.Fatal("two dead readings must remove the worktree and the branch")
	}
}

func TestSweepDoesNothingWhenTheProcessListFails(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.finish(dir)
	f.now = f.now.Add(time.Hour)
	f.setAlive(nil)

	for range 4 {
		f.items.sweep(context.Background())
	}
	if !exists(dir) {
		t.Fatal("an unknown reading removed the worktree")
	}
}

func TestSweepSkipsALaunchThatIsStillStarting(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.now = f.now.Add(time.Hour)
	f.setAlive(ptr(false))

	for range 3 {
		f.items.sweep(context.Background())
	}
	if !exists(dir) {
		t.Fatal("the worktree of a launch that has not finished was removed")
	}
}

func TestSweepWaitsOutTheGraceUnlessTheSessionWasSeen(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.finish(dir)
	ctx := context.Background()

	f.setAlive(ptr(false))
	for range 3 {
		f.items.sweep(ctx)
	}
	if !exists(dir) {
		t.Fatal("a session that never showed up must get its grace")
	}

	f.setAlive(ptr(true))
	f.items.sweep(ctx)
	f.setAlive(ptr(false))
	f.items.sweep(ctx)
	f.items.sweep(ctx)
	if exists(dir) {
		t.Fatal("a session seen alive needs no grace once it ends")
	}
}

func TestAdoptTracksTheSessionOfAFormerBridge(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.finish(dir)
	f.now = f.now.Add(time.Hour)

	later := newItemSessions(nil, f.items.readings)
	later.now = f.items.now
	later.adopt(map[string]string{"loupe": f.project})
	f.setAlive(ptr(false))
	later.sweep(context.Background())
	later.sweep(context.Background())

	if exists(dir) || hasBranch(t, f.project, "inbox-12") {
		t.Fatal("the new bridge must remove the worktree of an ended session")
	}
}

func TestAdoptRemovesAFolderWithNoRecordAndKeepsCardWorktrees(t *testing.T) {
	f := newItemFixture(t)
	card, err := ensureWorktree(context.Background(), f.project, "loupe", 87)
	if err != nil {
		t.Fatal(err)
	}
	orphan, _ := config.ItemWorktreeDir("loupe", 5)
	runGit(t, f.project, "worktree", "add", "-b", "inbox-5", orphan, "main")

	f.items.adopt(map[string]string{"loupe": f.project})

	if exists(orphan) || hasBranch(t, f.project, "inbox-5") {
		t.Fatal("a folder with no record belongs to no session")
	}
	if !exists(card) || !exists(f.project) {
		t.Fatal("adopt touched a card worktree or the project")
	}
}

func TestRemoveRefusesAPathThatIsNoItemWorktree(t *testing.T) {
	f := newItemFixture(t)
	card, _ := ensureWorktree(context.Background(), f.project, "loupe", 87)

	for _, path := range []string{card, f.project, filepath.Join(filepath.Dir(card), "item-x"), filepath.Join(filepath.Dir(card), "..", "item-1")} {
		if err := f.items.remove(context.Background(), path, f.project); err == nil {
			t.Fatalf("remove(%s) must refuse", path)
		}
	}
	if !exists(card) || !exists(f.project) {
		t.Fatal("a refused remove deleted something")
	}
}

func TestProjectDirsMapsEachSlugToItsFolder(t *testing.T) {
	set, dir := loadRules(t, strings.Replace(itemSessionRules, "{launcher}", "['{script}']", 1), rules.Defaults{})

	got := projectDirs(set)
	if len(got) != 1 || got["loupe"] != dir {
		t.Fatalf("dirs = %v, want loupe: %s", got, dir)
	}
	if got := projectDirs(nil); len(got) != 0 {
		t.Fatalf("dirs of no set = %v", got)
	}
}

// A Codex session shows no session id in the process list, so its session file
// decides: untouched for a day means it ended.
func TestACodexItemSessionEndsWhenItsSessionFileIsIdleForADay(t *testing.T) {
	f := newItemFixture(t)
	f.items.codex = func(string) bool { return true }
	var at *time.Time
	f.items.readings = func(_ context.Context, ids []string) []api.InteractiveSessionReading {
		return []api.InteractiveSessionReading{{SessionID: ids[0], TranscriptAt: at}}
	}
	dir, _ := f.prepare(12)
	f.items.finish(dir)
	ctx := context.Background()

	recent := f.now.Add(-time.Hour)
	at = &recent
	f.now = f.now.Add(2 * time.Hour)
	for range 3 {
		f.items.sweep(ctx)
	}
	if !exists(dir) {
		t.Fatal("a Codex session with a recent session file was removed")
	}
	if _, err := f.prepare(12); err == nil {
		t.Fatal("a Codex session with a recent session file must refuse a relaunch")
	}

	f.now = f.now.Add(24 * time.Hour)
	f.items.sweep(ctx)
	if !exists(dir) {
		t.Fatal("one idle reading removed the worktree")
	}
	f.items.sweep(ctx)
	if exists(dir) || hasBranch(t, f.project, "inbox-12") {
		t.Fatal("an idle Codex session must lose its worktree and branch")
	}
}

func TestACodexItemSessionWithNoSessionFileCountsFromItsLaunch(t *testing.T) {
	f := newItemFixture(t)
	f.items.codex = func(string) bool { return true }
	f.items.readings = func(_ context.Context, ids []string) []api.InteractiveSessionReading {
		return []api.InteractiveSessionReading{{SessionID: ids[0]}}
	}
	dir, _ := f.prepare(12)
	f.items.finish(dir)

	f.now = f.now.Add(23 * time.Hour)
	f.items.sweep(context.Background())
	f.items.sweep(context.Background())
	if !exists(dir) {
		t.Fatal("removed before a day")
	}
	f.now = f.now.Add(2 * time.Hour)
	f.items.sweep(context.Background())
	if _, err := f.prepare(12); err != nil {
		t.Fatalf("a relaunch after a day of silence must work: %v", err)
	}
}

func TestAFailedRemovalKeepsTheEntrySoTheNextSweepRetries(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.finish(dir)
	f.now = f.now.Add(time.Hour)
	f.setAlive(ptr(false))
	f.items.tracked[dir].projectDir = t.TempDir()

	f.items.sweep(context.Background())
	f.items.sweep(context.Background())
	if _, tracked := f.items.tracked[dir]; !tracked {
		t.Fatal("a failed removal dropped the entry")
	}
	if !exists(dir + ".session") {
		t.Fatal("a failed removal dropped the record")
	}

	f.items.tracked[dir].projectDir = f.project
	f.items.sweep(context.Background())
	if _, tracked := f.items.tracked[dir]; tracked || exists(dir) || exists(dir+".session") || hasBranch(t, f.project, "inbox-12") {
		t.Fatal("the retry must remove everything")
	}
}

func TestAFailedDiscardLeavesTheEntryForTheSweep(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.tracked[dir].projectDir = t.TempDir()

	f.items.discard(context.Background(), dir)
	e, tracked := f.items.tracked[dir]
	if !tracked || e.launching {
		t.Fatalf("entry = %+v, tracked = %v; the sweep must be able to retry", e, tracked)
	}
	f.items.tracked[dir].projectDir = f.project
	f.setAlive(ptr(false))
	f.items.sweep(context.Background())
	f.items.sweep(context.Background())
	if exists(dir) || hasBranch(t, f.project, "inbox-12") {
		t.Fatal("the sweep must finish the removal")
	}
}

func TestPrepareReplacesAWorktreeWhenItsSessionWasSeenAndEnded(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.finish(dir)

	f.setAlive(ptr(true))
	f.items.sweep(context.Background())
	f.setAlive(ptr(false))
	f.items.sweep(context.Background())
	if _, err := f.prepare(12); err != nil {
		t.Fatalf("a session seen alive that now reads dead gets no grace: %v", err)
	}
}

func TestPrepareKeepsTheWorktreeAfterOneDeadReading(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.finish(dir)
	f.setAlive(ptr(true))
	f.items.sweep(context.Background())

	f.setAlive(ptr(false))
	if _, err := f.prepare(12); err == nil || !strings.Contains(err.Error(), "try again") {
		t.Fatalf("err = %v", err)
	}
	if !exists(dir) || !hasBranch(t, f.project, "inbox-12") {
		t.Fatal("one dead reading removed the worktree of a session that may still run")
	}
	f.setAlive(ptr(true))
	if _, err := f.prepare(12); err == nil {
		t.Fatal("an alive reading must reset the count")
	}
	f.setAlive(ptr(false))
	if _, err := f.prepare(12); err == nil || !exists(dir) {
		t.Fatalf("a dead reading after an alive one is the first in a row: err = %v", err)
	}
}

func TestPrepareCountsTheReadingsOfAnUntrackedRecord(t *testing.T) {
	f := newItemFixture(t)
	dir, _ := f.prepare(12)
	f.items.finish(dir)
	f.now = f.now.Add(time.Hour)
	other := newItemSessions(nil, f.items.readings)
	other.now = f.items.now

	f.setAlive(ptr(false))
	ctx := context.Background()
	if _, err := other.prepare(ctx, f.project, "loupe", 12, itemSession); err == nil || !exists(dir) {
		t.Fatalf("one dead reading replaced the worktree of a former bridge: err = %v", err)
	}
	if _, err := other.prepare(ctx, f.project, "loupe", 12, itemSession); err != nil {
		t.Fatalf("the second dead reading in a row must replace it: %v", err)
	}
}
