//go:build unix

package cmd

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/event"
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

// A worktree on the adoption list that is gone loses its place, also when a new
// worktree later takes the same path.
func TestTheGuardRefusesANewWorktreeAtTheRemovedPathOfAnAdoptedOne(t *testing.T) {
	project := guardProject(t)
	older := addTree(t, project, "card-5")
	recordAdoptionFor(t, project)
	gitIn(t, project, "worktree", "remove", "--force", older)
	gitIn(t, project, "worktree", "add", "-q", "--detach", older)
	runDir := snapshotOf(t, project)

	got := checkFolder(context.Background(), folderCheck{folder: older, project: project, card: "c5", runDir: runDir})

	if !strings.HasPrefix(got, "folder-refused") || !strings.Contains(got, "resume the run") {
		t.Fatalf("checkFolder = %q", got)
	}
	if list, _ := readAdoption(); slices.Contains(list[project], older) {
		t.Fatalf("adoption still holds %s", older)
	}
}

// A run's list leaves out a worktree whose folder is gone, so the adoption list
// drops it at the next check.
func TestTheGuardDropsAGoneWorktreeFromTheAdoptionList(t *testing.T) {
	project := guardProject(t)
	gone := addTree(t, project, "card-5")
	recordAdoptionFor(t, project)
	if err := os.RemoveAll(gone); err != nil {
		t.Fatal(err)
	}
	runDir := snapshotOf(t, project)
	if paths, _ := readWorktreeSnapshot(runDir); slices.Contains(paths, gone) {
		t.Fatalf("snapshot holds the gone worktree: %v", paths)
	}
	tree := addTree(t, project, "card-6")

	if got := checkFolder(context.Background(), folderCheck{folder: tree, project: project, card: "c6", runDir: runDir}); got != "" {
		t.Fatalf("checkFolder = %q", got)
	}
	gitIn(t, project, "worktree", "prune", "--expire", "now")
	gitIn(t, project, "worktree", "add", "-q", "--detach", gone)
	if got := checkFolder(context.Background(), folderCheck{folder: gone, project: project, card: "c5", runDir: snapshotOf(t, project)}); !strings.HasPrefix(got, "folder-refused") {
		t.Fatalf("a worktree made by hand at the gone path: %q", got)
	}
}

// A before command that an image without the guard started has no list of its
// own. A worktree it makes after the first start of the guard passes as made
// in this run, and an older worktree passes through the adoption list.
func TestALegacyBeforeRunTakesTheAdoptionListAsItsList(t *testing.T) {
	project := guardProject(t)
	older := addTree(t, project, "card-5")
	recordAdoptionFor(t, project)
	runDir := t.TempDir()
	seedLegacySnapshot(project, runDir)
	made := addTree(t, project, "card-7")

	if got := checkFolder(context.Background(), folderCheck{folder: made, project: project, card: "c7", runDir: runDir}); got != "" {
		t.Fatalf("worktree of the legacy run refused: %q", got)
	}
	if claimOf(t, made) != "c7" {
		t.Fatalf("claim = %q, want c7", claimOf(t, made))
	}
	if got := checkFolder(context.Background(), folderCheck{folder: older, project: project, card: "c5", runDir: runDir}); got != "" {
		t.Fatalf("older worktree refused: %q", got)
	}
}

// A run with no list and no seed still fails closed.
func TestARunWithNoListRefusesAnUnclaimedWorktree(t *testing.T) {
	project := guardProject(t)
	recordAdoptionFor(t, project)
	tree := addTree(t, project, "card-7")

	if got := checkFolder(context.Background(), folderCheck{folder: tree, project: project, card: "c7", runDir: t.TempDir()}); !strings.HasPrefix(got, "folder-refused") {
		t.Fatalf("checkFolder = %q", got)
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
	list[project] = markAdoption(context.Background(), paths)
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

// gitBridge is a harness whose project dir is a git repository, with the real
// folder guard and a before command that runs make and prints its folder.
func gitBridge(t *testing.T, make func(project string) string) *harness {
	t.Helper()
	shortConfigHome(t)
	h, _, _ := withBefore(t, "1m")
	gitIn(t, h.dir, "init", "-q")
	gitIn(t, h.dir, "commit", "-q", "--allow-empty", "-m", "first")
	h.router.worker.folder = defaultWorkerOps().folder
	h.router.worker.before = func(ctx context.Context, spec procSpec, onStart func(workerProc)) procResult {
		onStart(workerProc{pid: 4242})
		runs, err := config.RunsDir()
		if err != nil {
			t.Fatal(err)
		}

		return procResult{dir: make(spec.dir), runDir: filepath.Join(runs, spec.runID)}
	}

	return h
}

// A bridge run saves the worktree list before its before command, so the
// worktree that command makes passes the guard and gets the claim of the card.
func TestABridgeRunClaimsTheWorktreeItsBeforeCommandMade(t *testing.T) {
	var tree string
	h := gitBridge(t, func(project string) string {
		tree = addTree(t, project, "card-87")

		return tree
	})
	rec := h.states()

	h.send(cardMoved(87))

	wantStates(t, rec.states(), api.RunQueued, api.RunPreparing, api.RunRunning, api.RunSucceeded)
	if calls := h.worker.recorded(); len(calls) != 1 || calls[0].dir != tree {
		t.Fatalf("worker = %+v, want it in %s", calls, tree)
	}
	if claimOf(t, tree) == "" {
		t.Fatalf("no claim in %s", tree)
	}
}

// A before command that prints the main checkout is judged, and refused.
func TestABridgeRunRefusesAPrintedMainCheckout(t *testing.T) {
	h := gitBridge(t, func(project string) string { return project })
	rec := h.states()

	h.send(cardMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunPreparing, api.RunFailed)
	if got := sent[2].report.Output; !strings.Contains(got, "folder-refused") || !strings.Contains(got, "main checkout") {
		t.Fatalf("output = %q", got)
	}
	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("worker started: %+v", calls)
	}
}

// A folder inside a worktree is not the worktree, although git answers there
// too, so the guard refuses it and claims nothing.
func TestTheGuardRefusesAFolderInsideAWorktree(t *testing.T) {
	project := guardProject(t)
	runDir := snapshotOf(t, project)
	tree := addTree(t, project, "card-1")
	sub := filepath.Join(tree, "sub")
	if err := os.MkdirAll(sub, 0o700); err != nil {
		t.Fatal(err)
	}

	got := checkFolder(context.Background(), folderCheck{folder: sub, project: project, card: "c1", runDir: runDir})

	if !strings.HasPrefix(got, "folder-refused") || !strings.Contains(got, "inside the worktree "+tree) {
		t.Fatalf("checkFolder = %q", got)
	}
	if claimOf(t, tree) != "" {
		t.Fatal("a refused folder was claimed")
	}
}

// Two checks that both read no claim cannot both claim the folder.
func TestOnlyOneCardWinsTheClaimOfANewWorktree(t *testing.T) {
	project := guardProject(t)
	runDir := snapshotOf(t, project)
	tree := addTree(t, project, "card-1")

	results := make([]string, 2)
	var wg sync.WaitGroup
	for i, card := range []string{"c1", "c2"} {
		wg.Go(func() {
			results[i] = checkFolder(context.Background(), folderCheck{folder: tree, project: project, card: card, runDir: runDir})
		})
	}
	wg.Wait()

	winner := claimOf(t, tree)
	passed := 0
	for i, card := range []string{"c1", "c2"} {
		switch {
		case results[i] == "" && card == winner:
			passed++
		case results[i] == "":
			t.Fatalf("%s passed, but %s holds the claim", card, winner)
		case !strings.Contains(results[i], "card "+winner+" claims it"):
			t.Fatalf("%s: %q", card, results[i])
		}
	}
	if passed != 1 {
		t.Fatalf("results = %q, claim %q", results, winner)
	}
}

// A second run cannot start in a folder that the guard let a first run start
// in, also while that run sits between its before command and its worker.
func TestTheGuardChecksOneRunAtATimeAndReservesTheFolder(t *testing.T) {
	h := newHarness(t)
	folder := realPath(t.TempDir())
	entered, release := make(chan string, 2), make(chan struct{})
	h.router.worker.folder = func(_ context.Context, c folderCheck) string {
		entered <- c.card
		if c.card == "a" {
			<-release
		}
		if slices.Contains(c.busy, folder) {
			return "folder-refused: busy"
		}

		return ""
	}
	run := func(id string) pending {
		return pending{runID: "run-" + id, event: event.Event{Type: event.WorkRequestType, CardNumber: 1, CardID: id}}
	}

	first := make(chan string)
	go func() { first <- h.router.refuseFolder(run("a"), procResult{dir: folder}) }()
	<-entered
	second := make(chan string)
	go func() { second <- h.router.refuseFolder(run("b"), procResult{dir: folder}) }()
	select {
	case card := <-entered:
		t.Fatalf("run %s entered the guard while run a was in it", card)
	case <-time.After(50 * time.Millisecond):
	}
	close(release)

	if got := <-first; got != "" {
		t.Fatalf("first run: %q", got)
	}
	if got := <-second; got == "" {
		t.Fatal("the second run got the folder of the first run")
	}
}

// The reservation of a run ends with the run, so the next run of the card
// starts in the same worktree.
func TestTheNextRunOfACardStartsInItsWorktree(t *testing.T) {
	var tree string
	h := gitBridge(t, func(project string) string {
		if tree == "" {
			tree = addTree(t, project, "card-87")
		}

		return tree
	})
	rec := h.states()

	h.send(cardMoved(87))
	h.send(cardMoved(87))

	wantStates(t, rec.states(), api.RunQueued, api.RunPreparing, api.RunRunning, api.RunSucceeded, api.RunQueued, api.RunPreparing, api.RunRunning, api.RunSucceeded)
	if calls := h.worker.recorded(); len(calls) != 2 || calls[0].dir != tree || calls[1].dir != tree {
		t.Fatalf("worker = %+v, want two runs in %s", calls, tree)
	}
}
