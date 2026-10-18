package cmd

import (
	"bytes"
	"context"
	"net/url"
	"os/exec"
	"slices"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// worktreeTimeout bounds the git calls of one heartbeat, all projects together.
const worktreeTimeout = 5 * time.Second

// commandEntryReports lists the command entries of the set for the heartbeat,
// in name order, at most api.MaxCommandEntries of them.
func commandEntryReports(set *rules.Set) []api.CommandEntryReport {
	entries := set.CommandEntries()
	out := make([]api.CommandEntryReport, 0, min(len(entries), api.MaxCommandEntries))
	for _, c := range entries[:min(len(entries), api.MaxCommandEntries)] {
		params := make([]api.CommandParamReport, 0, len(c.Entry.Params))
		for _, name := range sortedParamNames(c.Entry.Params) {
			p := c.Entry.Params[name]
			params = append(params, api.CommandParamReport{Name: name, Pattern: p.Pattern, Description: p.Description})
		}
		timeout := c.Entry.Timeout
		if timeout == "" {
			timeout = strings.TrimSuffix(rules.DefaultCommandTimeout.String(), "0s")
		}
		out = append(out, api.CommandEntryReport{Name: c.Name, Description: c.Entry.Description, Params: params, Run: c.Entry.Run, Timeout: timeout})
	}

	return out
}

func sortedParamNames(params map[string]rules.Param) []string {
	names := make([]string, 0, len(params))
	for name := range params {
		names = append(names, name)
	}
	slices.Sort(names)

	return names
}

// worktreeReports reads `git worktree list` in the folder of each followed
// project. It returns nil when any call fails, so the heartbeat keeps the rows
// the server holds. A set with no project returns nil too.
func worktreeReports(ctx context.Context, set *rules.Set) []api.WorktreeReport {
	slugs := set.Projects()
	if len(slugs) == 0 {
		return nil
	}
	ctx, cancel := context.WithTimeout(ctx, worktreeTimeout)
	defer cancel()
	out := []api.WorktreeReport{}
	for _, slug := range slugs {
		var stdout bytes.Buffer
		cmd := exec.CommandContext(ctx, "git", "-C", set.Dir(slug), "worktree", "list", "--porcelain")
		cmd.Stdout = &stdout
		if cmd.Run() != nil {
			return nil
		}
		id := set.ProjectID(slug)
		for _, w := range parseWorktrees(stdout.String()) {
			if len(w.Branch) > 255 || len(w.Path) > 4096 {
				continue
			}
			out = append(out, api.WorktreeReport{ProjectID: id, Branch: w.Branch, Path: w.Path})
		}
	}

	return out[:min(len(out), api.MaxWorktrees)]
}

// maxRemoteURL is the longest remote the server stores.
const maxRemoteURL = 2048

// repositoryReports reads the origin remote in the folder of each followed
// project. A folder with no readable remote still goes out, with its path and
// an empty remote, because the path says the project is linked.
func repositoryReports(ctx context.Context, set *rules.Set) []api.RepositoryReport {
	slugs := set.Projects()
	if len(slugs) == 0 {
		return nil
	}
	ctx, cancel := context.WithTimeout(ctx, worktreeTimeout)
	defer cancel()
	out := make([]api.RepositoryReport, 0, min(len(slugs), api.MaxRepositories))
	for _, slug := range slugs[:min(len(slugs), api.MaxRepositories)] {
		dir, id := set.Dir(slug), set.ProjectID(slug)
		if id == "" || len(dir) > 4096 {
			continue
		}
		remote := originRemote(ctx, dir)
		out = append(out, api.RepositoryReport{ProjectID: id, Path: dir, RemoteURL: remote})
	}

	return out
}

// originRemote is the origin remote of the repository in dir, without
// credentials, and "" when git has none to give.
func originRemote(ctx context.Context, dir string) string {
	var stdout bytes.Buffer
	cmd := exec.CommandContext(ctx, "git", "-C", dir, "remote", "get-url", "origin")
	cmd.Stdout = &stdout
	if cmd.Run() != nil {
		return ""
	}

	return withoutCredentials(strings.TrimSpace(stdout.String()))
}

// withoutCredentials drops the user and password of a URL remote, which a
// person may have written into it. An scp-style remote holds none, and a value
// over the server's limit gives "".
func withoutCredentials(remote string) string {
	if len(remote) > maxRemoteURL {
		return ""
	}
	if u, err := url.Parse(remote); err == nil && u.User != nil {
		u.User = nil

		return u.String()
	}

	return remote
}

// listWorktrees reads `git worktree list --porcelain` in dir. It reads git
// fresh at each call, so a worktree made a moment ago counts.
func listWorktrees(ctx context.Context, dir string) ([]worktree, error) {
	ctx, cancel := context.WithTimeout(ctx, worktreeTimeout)
	defer cancel()
	var stdout bytes.Buffer
	cmd := exec.CommandContext(ctx, "git", "-C", dir, "worktree", "list", "--porcelain")
	cmd.Stdout = &stdout
	if err := cmd.Run(); err != nil {
		return nil, err
	}

	return parseWorktrees(stdout.String()), nil
}

// worktreeDir is the folder where branch is checked out in the project at
// projectDir, and false when no folder holds it or git fails.
func (r *router) worktreeDir(ctx context.Context, projectDir, branch string) (string, bool) {
	list := r.worktrees
	if list == nil {
		list = listWorktrees
	}
	found, err := list(ctx, projectDir)
	if err != nil {
		r.log.Warn("worktree_list_failed", "dir", projectDir, "error", err.Error())

		return "", false
	}
	for _, w := range found {
		if w.Branch == branch {
			return w.Path, true
		}
	}

	return "", false
}

// worktree is one entry of `git worktree list --porcelain` that has a branch.
type worktree struct{ Branch, Path string }

// parseWorktrees reads the porcelain output. It skips a detached or bare
// entry, which has no branch.
func parseWorktrees(text string) []worktree {
	var out []worktree
	var path, branch string
	flush := func() {
		if path != "" && branch != "" {
			out = append(out, worktree{Branch: branch, Path: path})
		}
		path, branch = "", ""
	}
	for line := range strings.SplitSeq(text, "\n") {
		line = strings.TrimRight(line, "\r")
		switch {
		case line == "":
			flush()
		case strings.HasPrefix(line, "worktree "):
			flush()
			path = strings.TrimPrefix(line, "worktree ")
		case strings.HasPrefix(line, "branch "):
			branch = strings.TrimPrefix(strings.TrimPrefix(line, "branch "), "refs/heads/")
		}
	}
	flush()

	return out
}
