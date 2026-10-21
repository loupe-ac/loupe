package cmd

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// fetchTimeout bounds the best effort fetch of the default branch.
const fetchTimeout = 60 * time.Second

// worktreeMu serializes the git calls that change the project's worktree list.
var worktreeMu sync.Mutex

// worktreeCard is the card whose worktree the run of p works in, and 0 when the
// run works in the project folder. Only an agent run of a card with no before
// command takes a worktree, and only under worktree isolation.
func worktreeCard(p pending, set *rules.Set) int {
	w := p.workOrOrigin()
	if w.Kind == "" || !w.OnCard() || w.CardNumber < 1 || p.action != "" || p.spec.before != nil {
		return 0
	}
	if set.Isolation() != rules.IsolationWorktree {
		return 0
	}

	return w.CardNumber
}

// git runs git in dir and returns its combined output.
func git(ctx context.Context, dir string, args ...string) (string, error) {
	cmd := exec.CommandContext(ctx, "git", args...)
	cmd.Dir = dir
	cmd.Env = append(os.Environ(), "GIT_TERMINAL_PROMPT=0")
	out, err := cmd.CombinedOutput()

	return strings.TrimSpace(string(out)), err
}

// ensureWorktree returns the worktree of a card of a project, and makes it
// first when the folder is missing. A new worktree is detached at the default
// branch of the project, which the bridge fetches first when it can.
func ensureWorktree(ctx context.Context, projectDir, slug string, card int) (string, error) {
	path, err := config.WorktreeDir(slug, card)
	if err != nil {
		return "", err
	}
	worktreeMu.Lock()
	defer worktreeMu.Unlock()

	if _, err := os.Stat(filepath.Join(path, ".git")); err == nil {
		return path, nil
	}
	if _, err := os.Stat(path); err == nil {
		return "", fmt.Errorf("the folder %s exists and is not a git worktree", path)
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return "", fmt.Errorf("create the worktree folder: %w", err)
	}
	// A folder that was deleted by hand leaves an entry that blocks the new one.
	if out, err := git(ctx, projectDir, "worktree", "prune"); err != nil {
		return "", gitFailure("git worktree prune", out, err)
	}
	start := defaultBranch(ctx, projectDir)
	if out, err := git(ctx, projectDir, "worktree", "add", "--detach", path, start); err != nil {
		return "", gitFailure("git worktree add of "+start, out, err)
	}

	return path, nil
}

func gitFailure(what, out string, err error) error {
	if out == "" {
		return fmt.Errorf("%s failed: %w", what, err)
	}

	return fmt.Errorf("%s failed: %w: %s", what, err, out)
}

// defaultBranch is the commit-ish a new worktree starts at: the remote branch
// of the default branch of origin, or the local branch when the remote has none.
// It fetches the branch first and ignores a failed fetch.
func defaultBranch(ctx context.Context, dir string) string {
	fetchCtx, cancel := context.WithTimeout(ctx, fetchTimeout)
	defer cancel()
	branch := remoteDefaultBranch(fetchCtx, dir)
	if branch == "" {
		if ref, err := git(ctx, dir, "symbolic-ref", "--short", "HEAD"); err == nil {
			branch = ref
		}
	}
	if branch == "" {
		return "HEAD"
	}
	_, _ = git(fetchCtx, dir, "fetch", "origin", branch)
	if _, err := git(ctx, dir, "rev-parse", "--verify", "--quiet", "refs/remotes/origin/"+branch); err == nil {
		return "origin/" + branch
	}

	return branch
}

// remoteDefaultBranch names the default branch of origin. A clone records it as
// origin/HEAD, and a remote added by hand records nothing, so it asks the remote.
func remoteDefaultBranch(ctx context.Context, dir string) string {
	if ref, err := git(ctx, dir, "symbolic-ref", "--short", "refs/remotes/origin/HEAD"); err == nil {
		return strings.TrimPrefix(ref, "origin/")
	}
	out, err := git(ctx, dir, "ls-remote", "--symref", "origin", "HEAD")
	if err != nil {
		return ""
	}
	for _, line := range strings.Split(out, "\n") {
		if ref, ok := strings.CutPrefix(line, "ref: refs/heads/"); ok {
			if name, _, found := strings.Cut(ref, "\t"); found {
				return name
			}
		}
	}

	return ""
}
