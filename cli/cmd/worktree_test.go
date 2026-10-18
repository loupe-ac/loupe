package cmd

import (
	"context"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// isolateConfig points the config dir at a temporary folder, so a test never
// makes a worktree in the real one.
func isolateConfig(t *testing.T) {
	t.Helper()
	t.Setenv("HOME", t.TempDir())
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
}

func runGit(t *testing.T, dir string, args ...string) string {
	t.Helper()
	cmd := exec.Command("git", append([]string{"-c", "user.name=t", "-c", "user.email=t@t", "-c", "init.defaultBranch=main", "-c", "commit.gpgsign=false"}, args...)...)
	cmd.Dir = dir
	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("git %v: %v: %s", args, err, out)
	}

	return strings.TrimSpace(string(out))
}

// newRepo makes a repository in dir with one commit on main.
func newRepo(t *testing.T, dir string) {
	t.Helper()
	runGit(t, dir, "init")
	commitFile(t, dir, "first.txt")
}

func commitFile(t *testing.T, dir, name string) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(dir, name), []byte(name), 0o600); err != nil {
		t.Fatal(err)
	}
	runGit(t, dir, "add", name)
	runGit(t, dir, "commit", "-m", name)
}

func worktreePaths(t *testing.T, dir string) []string {
	t.Helper()
	var out []string
	for _, line := range strings.Split(runGit(t, dir, "worktree", "list", "--porcelain"), "\n") {
		if path, ok := strings.CutPrefix(line, "worktree "); ok {
			out = append(out, path)
		}
	}

	return out
}

func TestEnsureWorktreeMakesADetachedWorktreeOutsideTheRepository(t *testing.T) {
	isolateConfig(t)
	project := t.TempDir()
	newRepo(t, project)

	got, err := ensureWorktree(context.Background(), project, "loupe", 87)
	if err != nil {
		t.Fatal(err)
	}

	want, _ := config.WorktreeDir("loupe", 87)
	if got != want || filepath.Base(got) != "card-87" || filepath.Base(filepath.Dir(got)) != "loupe" {
		t.Fatalf("worktree = %s, want %s", got, want)
	}
	if _, err := os.Stat(filepath.Join(got, "first.txt")); err != nil {
		t.Fatalf("the worktree holds no checkout: %v", err)
	}
	if head := runGit(t, got, "rev-parse", "--abbrev-ref", "HEAD"); head != "HEAD" {
		t.Fatalf("HEAD = %s, want a detached HEAD", head)
	}
	if status := runGit(t, project, "status", "--porcelain"); status != "" {
		t.Fatalf("the source checkout is dirty: %s", status)
	}
}

func TestEnsureWorktreeReusesTheFolderOfTheCard(t *testing.T) {
	isolateConfig(t)
	project := t.TempDir()
	newRepo(t, project)
	first, err := ensureWorktree(context.Background(), project, "loupe", 87)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(first, "wip.txt"), []byte("wip"), 0o600); err != nil {
		t.Fatal(err)
	}

	second, err := ensureWorktree(context.Background(), project, "loupe", 87)
	if err != nil {
		t.Fatal(err)
	}

	if second != first {
		t.Fatalf("second = %s, want %s", second, first)
	}
	if _, err := os.Stat(filepath.Join(second, "wip.txt")); err != nil {
		t.Fatalf("the reuse lost the work of the first worker: %v", err)
	}
	if got := len(worktreePaths(t, project)); got != 2 {
		t.Fatalf("worktrees = %d, want the project and one card", got)
	}
}

func TestEnsureWorktreeStartsAtTheFetchedDefaultBranch(t *testing.T) {
	isolateConfig(t)
	origin := t.TempDir()
	newRepo(t, origin)
	project := filepath.Join(t.TempDir(), "clone")
	runGit(t, t.TempDir(), "clone", origin, project)
	commitFile(t, origin, "second.txt")

	got, err := ensureWorktree(context.Background(), project, "loupe", 5)
	if err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(got, "second.txt")); err != nil {
		t.Fatalf("the worktree starts before the default branch of origin: %v", err)
	}
}

func TestEnsureWorktreeAsksTheRemoteWhenOriginHeadIsAbsent(t *testing.T) {
	isolateConfig(t)
	origin := t.TempDir()
	newRepo(t, origin)
	project := t.TempDir()
	newRepo(t, project)
	runGit(t, project, "remote", "add", "origin", origin)
	runGit(t, project, "config", "remote.origin.followRemoteHEAD", "never")
	runGit(t, project, "fetch", "origin")
	runGit(t, project, "update-ref", "-d", "refs/remotes/origin/HEAD")
	runGit(t, project, "switch", "-c", "feature")
	commitFile(t, project, "feature.txt")
	commitFile(t, origin, "second.txt")

	got, err := ensureWorktree(context.Background(), project, "loupe", 6)
	if err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(got, "feature.txt")); err == nil {
		t.Fatal("the worktree starts at the feature branch of the source checkout")
	}
	if _, err := os.Stat(filepath.Join(got, "second.txt")); err != nil {
		t.Fatalf("the worktree starts before the default branch of origin: %v", err)
	}
}

func TestEnsureWorktreeRecoversFromAFolderDeletedByHand(t *testing.T) {
	isolateConfig(t)
	project := t.TempDir()
	newRepo(t, project)
	first, err := ensureWorktree(context.Background(), project, "loupe", 87)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.RemoveAll(first); err != nil {
		t.Fatal(err)
	}

	if _, err := ensureWorktree(context.Background(), project, "loupe", 87); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(first, ".git")); err != nil {
		t.Fatalf("the worktree is not back: %v", err)
	}
}

func TestEnsureWorktreeRefusesAFolderThatIsNoWorktree(t *testing.T) {
	isolateConfig(t)
	project := t.TempDir()
	newRepo(t, project)
	path, _ := config.WorktreeDir("loupe", 87)
	if err := os.MkdirAll(path, 0o700); err != nil {
		t.Fatal(err)
	}

	_, err := ensureWorktree(context.Background(), project, "loupe", 87)

	if err == nil || !strings.Contains(err.Error(), "is not a git worktree") {
		t.Fatalf("err = %v", err)
	}
}

func TestEnsureWorktreeFailsOutsideARepository(t *testing.T) {
	isolateConfig(t)

	_, err := ensureWorktree(context.Background(), t.TempDir(), "loupe", 87)

	if err == nil || !strings.Contains(err.Error(), "git worktree") {
		t.Fatalf("err = %v", err)
	}
}

// fakeWorktree stands in for ensureWorktree. It records its calls and answers
// with a folder per card.
type fakeWorktree struct {
	mu    sync.Mutex
	calls []int
	root  string
	err   error
}

func (f *fakeWorktree) ensure(_ context.Context, _, _ string, card int) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.calls = append(f.calls, card)
	if f.err != nil {
		return "", f.err
	}

	return filepath.Join(f.root, "card-"+strconv.Itoa(card)), nil
}

func (f *fakeWorktree) recorded() []int {
	f.mu.Lock()
	defer f.mu.Unlock()

	return slices.Clone(f.calls)
}

func withWorktree(t *testing.T, body string) (*harness, *fakeWorktree) {
	t.Helper()
	h := newHarnessWith(t, body, rules.Defaults{})
	f := &fakeWorktree{root: t.TempDir()}
	h.router.worker.worktree = f.ensure

	return h, f
}

func TestAWorkerRunsInTheWorktreeOfItsCard(t *testing.T) {
	h, f := withWorktree(t, defaultRules)

	h.send(cardMoved(87))
	h.send(cardMoved(87))
	h.send(cardMoved(88))

	calls := h.worker.recorded()
	if len(calls) != 3 || calls[0].dir != filepath.Join(f.root, "card-87") || calls[1].dir != calls[0].dir || calls[2].dir == calls[0].dir {
		t.Fatalf("worker dirs = %+v", calls)
	}
	if got := f.recorded(); !slices.Equal(got, []int{87, 87, 88}) {
		t.Fatalf("worktree calls = %v", got)
	}
}

func TestABeforeCommandWinsOverTheWorktree(t *testing.T) {
	h, _, folder := withBefore(t, "1m")
	f := &fakeWorktree{root: t.TempDir()}
	h.router.worker.worktree = f.ensure

	h.send(cardMoved(87))

	if got := f.recorded(); len(got) != 0 {
		t.Fatalf("worktree calls = %v, want none for an entry with a before command", got)
	}
	if calls := h.worker.recorded(); len(calls) != 1 || calls[0].dir != folder {
		t.Fatalf("worker = %+v, want it in %s", calls, folder)
	}
}

func TestNoIsolationKeepsTheWorkerInTheProjectFolder(t *testing.T) {
	h, f := withWorktree(t, strings.Replace(defaultRules, "defaults:\n", "defaults:\n  isolation: none\n", 1))

	h.send(cardMoved(87))

	if got := f.recorded(); len(got) != 0 {
		t.Fatalf("worktree calls = %v", got)
	}
	if calls := h.worker.recorded(); len(calls) != 1 || calls[0].dir != h.dir {
		t.Fatalf("worker = %+v, want it in %s", calls, h.dir)
	}
}

func TestAFailedWorktreeFailsTheRunWithItsReason(t *testing.T) {
	h, f := withWorktree(t, defaultRules)
	f.err = errors.New("git worktree add of main failed: fatal: not a git repository")
	rec := h.states()

	h.send(cardMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunFailed)
	failed := sent[1].report
	if failed.ExitCode == nil || *failed.ExitCode != -1 || !strings.Contains(failed.Output, "cannot make the worktree of the card: git worktree add of main failed") {
		t.Fatalf("failed = %+v", failed)
	}
	if h.runs() != 0 || h.used() != 0 || h.cardHeld(87) {
		t.Fatalf("runs = %d, used = %d, card held = %v", h.runs(), h.used(), h.cardHeld(87))
	}
}

// The bridge removes the worktree of a card on its teardown request, prunes
// the list and starts no agent. It runs the real git, as the command does.
func TestTeardownRemovesTheWorktreeOfTheCard(t *testing.T) {
	isolateConfig(t)
	h := newHarness(t)
	newRepo(t, h.dir)
	path, err := ensureWorktree(context.Background(), h.dir, "loupe", 87)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(path, "dirty.txt"), []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}
	rec := h.states()

	h.send(doneMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	if _, err := os.Stat(path); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("the worktree folder is still there: %v", err)
	}
	if got := worktreePaths(t, h.dir); len(got) != 1 {
		t.Fatalf("worktrees = %v, want the project alone", got)
	}
	if h.runs() != 0 || h.cardHeld(87) {
		t.Fatalf("runs = %d, card held = %v", h.runs(), h.cardHeld(87))
	}
}

func TestTeardownSucceedsWhenTheFolderIsAlreadyGone(t *testing.T) {
	isolateConfig(t)
	h := newHarness(t)
	newRepo(t, h.dir)
	path, err := ensureWorktree(context.Background(), h.dir, "loupe", 87)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.RemoveAll(path); err != nil {
		t.Fatal(err)
	}
	rec := h.states()

	h.send(doneMoved(87))

	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunSucceeded)
	if got := worktreePaths(t, h.dir); len(got) != 1 {
		t.Fatalf("worktrees = %v, want the prune to drop the stale entry", got)
	}
}

func TestTeardownSucceedsWhenTheCardNeverHadAWorktree(t *testing.T) {
	isolateConfig(t)
	h := newHarness(t)
	newRepo(t, h.dir)
	rec := h.states()

	h.send(doneMoved(87))

	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunSucceeded)
}

func TestTeardownEntryOfTheUserWinsOverTheBridge(t *testing.T) {
	isolateConfig(t)
	h, f := withCommand(t, "1m")
	rec := h.states()

	h.send(doneMoved(87))

	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunSucceeded)
	if specs := f.recorded(); len(specs) != 1 || specs[0].argv[0] != "teardown" {
		t.Fatalf("command = %+v, want the entry of the user", specs)
	}
}
