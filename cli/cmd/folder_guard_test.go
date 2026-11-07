//go:build unix

package cmd

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

func gitIn(t *testing.T, dir string, args ...string) {
	t.Helper()
	full := append([]string{"-C", dir, "-c", "user.name=t", "-c", "user.email=t@t", "-c", "init.defaultBranch=main"}, args...)
	if out, err := exec.Command("git", full...).CombinedOutput(); err != nil {
		t.Fatalf("git %v: %v\n%s", args, err, out)
	}
}

// guardProject makes a git repository with one commit, in a config home of its own.
func guardProject(t *testing.T) string {
	t.Helper()
	shortConfigHome(t)
	project := realPath(t.TempDir())
	gitIn(t, project, "init", "-q")
	gitIn(t, project, "commit", "-q", "--allow-empty", "-m", "first")

	return project
}

// addTree makes a linked worktree beside the project.
func addTree(t *testing.T, project, name string) string {
	t.Helper()
	path := filepath.Join(realPath(t.TempDir()), name)
	gitIn(t, project, "worktree", "add", "-q", "--detach", path)

	return realPath(path)
}

func snapshotOf(t *testing.T, project string) string {
	t.Helper()
	runDir := t.TempDir()
	writeWorktreeSnapshot(context.Background(), project, runDir)

	return runDir
}

func claimOf(t *testing.T, folder string) string {
	t.Helper()
	gitDir, _, err := gitDirs(context.Background(), folder)
	if err != nil {
		t.Fatal(err)
	}
	b, err := os.ReadFile(filepath.Join(gitDir, claimFile))
	if err != nil {
		return ""
	}

	return strings.TrimSpace(string(b))
}

func TestTheGuardRefusesTheMainCheckout(t *testing.T) {
	project := guardProject(t)

	got := checkFolder(context.Background(), folderCheck{folder: project, project: project, card: "c1", runDir: snapshotOf(t, project)})

	if !strings.HasPrefix(got, "folder-refused") || !strings.Contains(got, "main checkout") {
		t.Fatalf("checkFolder = %q", got)
	}
}

func TestTheGuardRefusesAFolderOfAnotherRepository(t *testing.T) {
	project := guardProject(t)
	other := guardProject(t)
	tree := addTree(t, other, "x")

	got := checkFolder(context.Background(), folderCheck{folder: tree, project: project, card: "c1", runDir: snapshotOf(t, project)})

	if !strings.Contains(got, "another repository") {
		t.Fatalf("checkFolder = %q", got)
	}
}

func TestTheGuardRefusesAFolderThatIsNotAWorktree(t *testing.T) {
	project := guardProject(t)

	got := checkFolder(context.Background(), folderCheck{folder: t.TempDir(), project: project, card: "c1", runDir: snapshotOf(t, project)})

	if !strings.HasPrefix(got, "folder-refused") {
		t.Fatalf("checkFolder = %q", got)
	}
}

// A worktree that the before command made claims itself for the card, and the
// card passes again on a resume while another card is refused.
func TestTheGuardClaimsAFolderTheBeforeCommandMade(t *testing.T) {
	project := guardProject(t)
	runDir := snapshotOf(t, project)
	tree := addTree(t, project, "card-1")

	if got := checkFolder(context.Background(), folderCheck{folder: tree, project: project, card: "c1", runDir: runDir}); got != "" {
		t.Fatalf("first run refused: %q", got)
	}
	if claimOf(t, tree) != "c1" {
		t.Fatalf("claim = %q, want c1", claimOf(t, tree))
	}
	resume := snapshotOf(t, project)
	if got := checkFolder(context.Background(), folderCheck{folder: tree, project: project, card: "c1", runDir: resume}); got != "" {
		t.Fatalf("resume refused: %q", got)
	}
	got := checkFolder(context.Background(), folderCheck{folder: tree, project: project, card: "c2", runDir: resume})
	if !strings.HasPrefix(got, "folder-refused") || !strings.Contains(got, "c1") {
		t.Fatalf("other card: %q", got)
	}
}

func TestTheGuardRefusesAFolderAnotherRunUses(t *testing.T) {
	project := guardProject(t)
	runDir := snapshotOf(t, project)
	tree := addTree(t, project, "card-1")

	got := checkFolder(context.Background(), folderCheck{folder: tree, project: project, card: "c1", runDir: runDir, busy: []string{tree}})

	if !strings.Contains(got, "another run") {
		t.Fatalf("checkFolder = %q", got)
	}
	if claimOf(t, tree) != "" {
		t.Fatal("a refused folder was claimed")
	}
}

// A worktree that existed before the before command and that no card claims
// is one a person or a tool made, so the reason says how to adopt it.
func TestTheGuardRefusesAnUnclaimedWorktreeAndNamesTheAdoption(t *testing.T) {
	project := guardProject(t)
	recordAdoptionFor(t, project)
	tree := addTree(t, project, "by-hand")
	runDir := snapshotOf(t, project)

	got := checkFolder(context.Background(), folderCheck{folder: tree, project: project, card: "c1", runDir: runDir})

	gitDir, _, _ := gitDirs(context.Background(), tree)
	if !strings.HasPrefix(got, "folder-refused") || !strings.Contains(got, filepath.Join(gitDir, claimFile)) || !strings.Contains(got, "resume the run") {
		t.Fatalf("checkFolder = %q", got)
	}
	// The adoption the reason names works: the card id in the claim file.
	if err := os.WriteFile(filepath.Join(gitDir, claimFile), []byte("c1\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := checkFolder(context.Background(), folderCheck{folder: tree, project: project, card: "c1", runDir: runDir}); got != "" {
		t.Fatalf("after adoption: %q", got)
	}
}

// A card worktree that an older CLI made resumes with no refusal, once, and a
// worktree made after the first start does not.
func TestTheGuardAdoptsTheWorktreesOfTheFirstStartOnce(t *testing.T) {
	project := guardProject(t)
	older := addTree(t, project, "card-5")
	recordAdoptionFor(t, project)
	later := addTree(t, project, "card-6")
	runDir := snapshotOf(t, project)

	if got := checkFolder(context.Background(), folderCheck{folder: older, project: project, card: "c5", runDir: runDir}); got != "" {
		t.Fatalf("older worktree refused: %q", got)
	}
	if claimOf(t, older) != "c5" {
		t.Fatalf("claim = %q, want c5", claimOf(t, older))
	}
	if list, _ := readAdoption(); slices.Contains(list[project], older) {
		t.Fatalf("adoption still holds %s", older)
	}
	if got := checkFolder(context.Background(), folderCheck{folder: later, project: project, card: "c6", runDir: runDir}); !strings.HasPrefix(got, "folder-refused") {
		t.Fatalf("later unclaimed worktree: %q", got)
	}
}

// The run directory holds the list from before the command, so an adopted run
// after a handover reads the same list.
func TestTheSnapshotListsDetachedWorktreesToo(t *testing.T) {
	project := guardProject(t)
	tree := addTree(t, project, "card-1")

	paths, ok := readWorktreeSnapshot(snapshotOf(t, project))

	if !ok || !slices.Contains(paths, tree) || !slices.Contains(paths, project) {
		t.Fatalf("snapshot = %v, %v", paths, ok)
	}
}

func recordAdoptionFor(t *testing.T, project string) {
	t.Helper()
	paths, err := listWorktreePaths(context.Background(), project)
	if err != nil {
		t.Fatal(err)
	}
	adoptionMu.Lock()
	defer adoptionMu.Unlock()
	list, err := readAdoption()
	if err != nil {
		t.Fatal(err)
	}
	list[project] = paths
	if err := writeAdoption(list); err != nil {
		t.Fatal(err)
	}
}

// A refused folder fails the run before any worker starts.
func TestARefusedFolderFailsTheRunAndStartsNoWorker(t *testing.T) {
	shortConfigHome(t)
	h, _, _ := withBefore(t, "1m")
	h.router.worker.folder = nil
	rec := h.states()

	h.send(cardMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunPreparing, api.RunFailed)
	if got := sent[2].report.Output; !strings.Contains(got, "folder-refused") {
		t.Fatalf("output = %q", got)
	}
	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("worker started: %+v", calls)
	}
}
