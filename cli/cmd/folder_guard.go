package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

const (
	// claimFile is the file in the git dir of a worktree that names the card
	// which owns the worktree. The git dir lies outside the working tree, so
	// no commit carries the file, and it goes away with the worktree.
	claimFile = "loupe-card"
	// worktreeSnapshot is the file in a run directory that holds the worktree
	// paths from just before the before command.
	worktreeSnapshot = "worktrees.json"
	// adoptionFile is the file in the config folder that holds the adoption
	// list.
	adoptionFile = "adoption.json"
	// adoptionMark is the file in the git dir of a worktree on the adoption
	// list. It goes away with the worktree, so a new worktree at the same
	// path is not on the list.
	adoptionMark = "loupe-adopt"
	// folderRefused starts the reason of a refused folder.
	folderRefused = "folder-refused"
	// folderGitTimeout bounds the git calls of one folder check.
	folderGitTimeout = 10 * time.Second
)

// adoptionMu guards adoption.json against two runs that read and write it.
var adoptionMu sync.Mutex

// folderCheck is what the guard needs to judge the folder a before command
// printed.
type folderCheck struct {
	// folder is the printed folder, and project the project folder.
	folder, project string
	// card is the card id of the run, and "" for a run with no card.
	card string
	// runDir holds the worktree snapshot of the run.
	runDir string
	// busy holds the folders that other live runs of the bridge work in.
	busy []string
}

// listWorktreePaths lists the path of every worktree of the repository in dir,
// also a detached or bare one. A worktree whose folder is gone stays
// registered until a prune, and git marks it prunable. It is left out, so a
// folder that a before command makes again at that path counts as new.
func listWorktreePaths(ctx context.Context, dir string) ([]string, error) {
	ctx, cancel := context.WithTimeout(ctx, folderGitTimeout)
	defer cancel()
	var stdout bytes.Buffer
	cmd := exec.CommandContext(ctx, "git", "-C", dir, "worktree", "list", "--porcelain")
	cmd.Stdout = &stdout
	if err := cmd.Run(); err != nil {
		return nil, fmt.Errorf("git worktree list: %w", err)
	}
	var out []string
	for block := range strings.SplitSeq(strings.ReplaceAll(stdout.String(), "\r", ""), "\n\n") {
		path, prunable := "", false
		for line := range strings.SplitSeq(block, "\n") {
			if p, ok := strings.CutPrefix(line, "worktree "); ok {
				path = p
			}
			if line == "prunable" || strings.HasPrefix(line, "prunable ") {
				prunable = true
			}
		}
		if path != "" && !prunable {
			out = append(out, realPath(path))
		}
	}

	return out, nil
}

// realPath resolves symlinks, because git prints real paths and a before
// command may print a link. A path that does not resolve stays cleaned.
func realPath(path string) string {
	if resolved, err := filepath.EvalSymlinks(path); err == nil {
		return resolved
	}

	return filepath.Clean(path)
}

type snapshotFile struct {
	Paths []string `json:"paths"`
}

// writeWorktreeSnapshot keeps the worktree paths of the project in the run
// directory, so a bridge that adopts the run after a handover reads the same
// list. A failed read keeps no list, and the guard then fails closed.
func writeWorktreeSnapshot(ctx context.Context, project, runDir string) {
	paths, err := listWorktreePaths(ctx, project)
	if err != nil {
		return
	}
	b, err := json.Marshal(snapshotFile{Paths: paths})
	if err != nil || os.MkdirAll(runDir, 0o700) != nil {
		return
	}
	_ = os.WriteFile(filepath.Join(runDir, worktreeSnapshot), b, 0o600)
}

// readWorktreeSnapshot reads the list of writeWorktreeSnapshot. ok is false
// when the run directory holds none.
func readWorktreeSnapshot(runDir string) ([]string, bool) {
	b, err := os.ReadFile(filepath.Join(runDir, worktreeSnapshot))
	if err != nil {
		return nil, false
	}
	var f snapshotFile
	if json.Unmarshal(b, &f) != nil {
		return nil, false
	}

	return f.Paths, true
}

// adoptionList maps a project folder to the worktree paths that existed at the
// first start of a CLI with the guard.
type adoptionList map[string][]string

func readAdoption() (adoptionList, error) {
	dir, err := config.Dir()
	if err != nil {
		return nil, err
	}
	b, err := os.ReadFile(filepath.Join(dir, adoptionFile))
	if errors.Is(err, fs.ErrNotExist) {
		return adoptionList{}, nil
	}
	if err != nil {
		return nil, fmt.Errorf("read %s: %w", adoptionFile, err)
	}
	list := adoptionList{}
	if err := json.Unmarshal(b, &list); err != nil {
		return nil, fmt.Errorf("parse %s: %w", adoptionFile, err)
	}

	return list, nil
}

func writeAdoption(list adoptionList) error {
	dir, err := config.Dir()
	if err != nil {
		return err
	}
	b, err := json.MarshalIndent(list, "", "  ")
	if err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(dir, adoptionFile+".*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(b); err != nil {
		tmp.Close()

		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}

	return os.Rename(tmp.Name(), filepath.Join(dir, adoptionFile))
}

// recordAdoption stores the worktree paths of every followed project that has
// no entry yet. It runs at the first start of a CLI with the guard, before any
// worker starts, and never adds to an entry later. A project that fails to
// list keeps no entry, so a later run records it. An entry that exists loses
// each path whose worktree is gone.
func recordAdoption(ctx context.Context, set *rules.Set) {
	adoptionMu.Lock()
	defer adoptionMu.Unlock()
	list, err := readAdoption()
	if err != nil {
		return
	}
	changed := false
	for _, slug := range set.Projects() {
		dir := set.Dir(slug)
		paths, err := listWorktreePaths(ctx, dir)
		if err != nil {
			continue
		}
		entry, found := list[dir]
		if !found {
			list[dir], changed = markAdoption(ctx, paths), true

			continue
		}
		kept := slices.DeleteFunc(slices.Clone(entry), func(p string) bool { return !slices.Contains(paths, p) })
		if len(kept) != len(entry) {
			list[dir], changed = kept, true
		}
	}
	if changed {
		_ = writeAdoption(list)
	}
}

// markAdoption writes the adoption mark into the git dir of each linked
// worktree of paths, and returns the paths it marked. The main checkout and a
// path it cannot mark stay off the list.
func markAdoption(ctx context.Context, paths []string) []string {
	marked := []string{}
	for _, path := range paths {
		dirs, err := revParsePaths(ctx, path, "--git-dir", "--git-common-dir")
		if err != nil || dirs[0] == dirs[1] {
			continue
		}
		if os.WriteFile(filepath.Join(dirs[0], adoptionMark), nil, 0o600) == nil {
			marked = append(marked, path)
		}
	}

	return marked
}

// inAdoption reports whether the list of project holds path, and its git dir
// gitDir still holds the adoption mark. A project with no entry yet takes the
// snapshot of this run as its list, which is the state before the first
// before command of the project. A known snapshot also drops each path whose
// worktree was gone before this run, and a path without its mark leaves the
// list.
func inAdoption(ctx context.Context, project, path, gitDir string, snapshot []string, known bool) bool {
	adoptionMu.Lock()
	defer adoptionMu.Unlock()
	list, err := readAdoption()
	if err != nil {
		return false
	}
	entry, found := list[project]
	if !found {
		if !known {
			return false
		}
		entry = markAdoption(ctx, snapshot)
	}
	kept := slices.DeleteFunc(slices.Clone(entry), func(p string) bool {
		return known && !slices.Contains(snapshot, p)
	})
	_, err = os.Stat(filepath.Join(gitDir, adoptionMark))
	ok := slices.Contains(kept, path) && err == nil
	if !ok {
		kept = slices.DeleteFunc(kept, func(p string) bool { return p == path })
	}
	if !found || !slices.Equal(kept, entry) {
		list[project] = kept
		_ = writeAdoption(list)
	}

	return ok
}

// dropAdoption removes path from the list of project, and its mark from gitDir,
// once a card claims it.
func dropAdoption(project, path, gitDir string) {
	adoptionMu.Lock()
	defer adoptionMu.Unlock()
	_ = os.Remove(filepath.Join(gitDir, adoptionMark))
	list, err := readAdoption()
	if err != nil {
		return
	}
	entry, found := list[project]
	if !found || !slices.Contains(entry, path) {
		return
	}
	list[project] = slices.DeleteFunc(slices.Clone(entry), func(p string) bool { return p == path })
	_ = writeAdoption(list)
}

// seedLegacySnapshot gives a run that an image without the guard started the
// worktree list it lacks. That image took no list before its before command,
// so the adoption list of the project stands in: it holds the worktrees from
// before this image adopted the run, and a worktree the command makes later
// is not on it. A run directory that holds a list, or a project with no
// entry, stays as it is.
func seedLegacySnapshot(project, runDir string) {
	if _, known := readWorktreeSnapshot(runDir); known {
		return
	}
	adoptionMu.Lock()
	list, err := readAdoption()
	adoptionMu.Unlock()
	if err != nil {
		return
	}
	entry, found := list[project]
	if !found {
		return
	}
	b, err := json.Marshal(snapshotFile{Paths: entry})
	if err != nil {
		return
	}
	_ = os.WriteFile(filepath.Join(runDir, worktreeSnapshot), b, 0o600)
}

// gitDirs prints the per-worktree git dir and the common git dir of dir.
func gitDirs(ctx context.Context, dir string) (string, string, error) {
	paths, err := revParsePaths(ctx, dir, "--git-dir", "--git-common-dir")
	if err != nil {
		return "", "", err
	}

	return paths[0], paths[1], nil
}

// revParsePaths prints one absolute, resolved path for each rev-parse option.
func revParsePaths(ctx context.Context, dir string, options ...string) ([]string, error) {
	ctx, cancel := context.WithTimeout(ctx, folderGitTimeout)
	defer cancel()
	var stdout bytes.Buffer
	cmd := exec.CommandContext(ctx, "git", append([]string{"-C", dir, "rev-parse", "--path-format=absolute"}, options...)...)
	cmd.Stdout = &stdout
	if err := cmd.Run(); err != nil {
		return nil, fmt.Errorf("git rev-parse: %w", err)
	}
	lines := strings.Split(strings.TrimSpace(stdout.String()), "\n")
	if len(lines) != len(options) {
		return nil, fmt.Errorf("git rev-parse printed %d lines, want %d", len(lines), len(options))
	}
	for i, line := range lines {
		lines[i] = realPath(strings.TrimSpace(line))
	}

	return lines, nil
}

// checkFolder judges the folder a before command printed, and returns "" when a
// worker may start in it. Otherwise it returns the reason, which starts with
// folder-refused. A folder that passes the claim step is claimed for the card
// before it returns.
func checkFolder(ctx context.Context, c folderCheck) string {
	refuse := func(why string) string {
		return fmt.Sprintf("%s: the folder %s is refused: %s", folderRefused, c.folder, why)
	}
	folder := realPath(c.folder)
	paths, err := revParsePaths(ctx, folder, "--show-toplevel", "--git-dir", "--git-common-dir")
	if err != nil {
		return refuse("it is not a git worktree (" + err.Error() + ")")
	}
	top, gitDir, common := paths[0], paths[1], paths[2]
	if top != folder {
		return refuse("it is a folder inside the worktree " + top + ", not the worktree itself")
	}
	_, projectCommon, err := gitDirs(ctx, c.project)
	if err != nil {
		return refuse("the project folder is not a git repository (" + err.Error() + ")")
	}
	if gitDir == common {
		return refuse("it is the main checkout, where a worker must never work")
	}
	if common != projectCommon {
		return refuse("it is a worktree of another repository than the project")
	}
	for _, busy := range c.busy {
		if realPath(busy) == folder {
			return refuse("another run of this bridge works in it now")
		}
	}
	if c.card == "" {
		return ""
	}

	claimPath := filepath.Join(gitDir, claimFile)
	claimed, err := os.ReadFile(claimPath)
	switch {
	case err == nil:
		if owner := strings.TrimSpace(string(claimed)); owner != c.card {
			return refuse("card " + owner + " claims it")
		}

		return ""
	case !errors.Is(err, fs.ErrNotExist):
		return refuse("the bridge cannot read its claim file: " + err.Error())
	}

	snapshot, known := readWorktreeSnapshot(c.runDir)
	existed := !known || slices.Contains(snapshot, folder)
	if existed && !inAdoption(ctx, c.project, folder, gitDir, snapshot, known) {
		return refuse("no card claims it, and the bridge did not make it in this run, so a person or another tool can have made it. " +
			"To let this card use it, write the card id into " + claimPath + ", then resume the run")
	}
	if why := writeClaim(claimPath, c.card); why != "" {
		return refuse(why)
	}
	if existed {
		dropAdoption(c.project, folder, gitDir)
	}

	return ""
}

// writeClaim creates the claim file of card, and fails when the file exists, so
// two checks that both read no claim cannot both win. The card id goes into a
// temporary file that a hard link then puts in place, so a reader never sees
// a claim without its card id. A claim that another check wrote first passes
// when it names the same card.
func writeClaim(path, card string) string {
	tmp, err := os.CreateTemp(filepath.Dir(path), claimFile+".*")
	if err != nil {
		return "the bridge cannot write its claim file: " + err.Error()
	}
	defer os.Remove(tmp.Name())
	_, err = tmp.WriteString(card + "\n")
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err == nil {
		err = os.Link(tmp.Name(), path)
	}
	if errors.Is(err, fs.ErrExist) {
		claimed, err := os.ReadFile(path)
		if err != nil {
			return "the bridge cannot read its claim file: " + err.Error()
		}
		if owner := strings.TrimSpace(string(claimed)); owner != card {
			return "card " + owner + " claims it"
		}

		return ""
	}
	if err != nil {
		return "the bridge cannot write its claim file: " + err.Error()
	}

	return ""
}
