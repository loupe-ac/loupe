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
