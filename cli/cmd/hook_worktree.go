package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/loupe-ac/loupe/cli/internal/config"
)

const (
	hookCreateEvent = "WorktreeCreate"
	hookRemoveEvent = "WorktreeRemove"
	// hookClaimFile sits in the per-worktree git dir of a worktree that
	// worktree-create made, and holds its name and project slug as a
	// hookClaim. Teardown removes no other worktree, except one in the
	// bridge's own worktree folder.
	hookClaimFile = "loupe-hook-worktree"
	// defaultHookTimeout applies to a hook that sets no timeout.
	defaultHookTimeout = 600 * time.Second
)

// newHookCmd groups the adapters that run a Claude Code hook of the project as
// a bridge command.
func newHookCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:    "hook",
		Short:  "Run a Claude Code hook of the project as a bridge command",
		Hidden: true,
	}
	cmd.AddCommand(newHookWorktreeCreateCmd(), newHookWorktreeRemoveCmd())

	return cmd
}

func newHookWorktreeCreateCmd() *cobra.Command {
	var slug string
	cmd := &cobra.Command{
		Use:   "worktree-create <name>",
		Short: "Run the WorktreeCreate hook for a worktree and print its folder",
		Long: "Runs the WorktreeCreate hook of .claude/settings.json or .claude/settings.local.json in the " +
			"current folder, the project folder, and prints the last line of its output, which is the worktree folder. " +
			"It writes a claim into the git dir of the worktree at the folder the hook printed, so worktree-remove can tell it from a worktree a person made. " +
			"When a worktree of that name exists, it prints that folder and skips the hook, because a before command runs again on each resume. " +
			"It does not claim that worktree. A worktree in the worktree folder of the bridge counts only when it is the folder of the --project slug, and a claimed worktree only when its claim names that name and slug, in any folder and on any branch.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			project, err := os.Getwd()
			if err != nil {
				return err
			}

			return runWorktreeCreate(cmd.Context(), project, slug, args[0], cmd.OutOrStdout(), cmd.ErrOrStderr())
		},
	}
	cmd.Flags().StringVar(&slug, "project", "", "the slug of the bridge project, which names its worktree folder")

	return cmd
}

func newHookWorktreeRemoveCmd() *cobra.Command {
	var slug string
	cmd := &cobra.Command{
		Use:   "worktree-remove <name>",
		Short: "Run the WorktreeRemove hook for a worktree",
		Long: "Finds the worktree of that name with git and runs the WorktreeRemove hook of .claude/settings.json or " +
			".claude/settings.local.json in the current folder, the project folder. It does nothing when the " +
			"project has no such worktree or no such hook, or when worktree-create did not make the worktree and it is not the bridge worktree of the --project slug.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			project, err := os.Getwd()
			if err != nil {
				return err
			}

			return runWorktreeRemove(cmd.Context(), project, slug, args[0], cmd.OutOrStdout(), cmd.ErrOrStderr())
		},
	}
	cmd.Flags().StringVar(&slug, "project", "", "the slug of the bridge project, which names its worktree folder")

	return cmd
}

// settingsHook is one command hook of a Claude Code settings file.
type settingsHook struct {
	Command string
	Timeout time.Duration
}

// readSettingsHooks lists the command hooks of event in settings.json and then
// settings.local.json under the .claude folder of project. A missing file adds
// none, and a file that does not parse is an error.
func readSettingsHooks(project, event string) ([]settingsHook, error) {
	var out []settingsHook
	for _, name := range []string{"settings.json", "settings.local.json"} {
		path := filepath.Join(project, ".claude", name)
		b, err := os.ReadFile(path)
		if errors.Is(err, os.ErrNotExist) {
			continue
		}
		if err != nil {
			return nil, err
		}
		var file struct {
			Hooks map[string][]struct {
				Hooks []struct {
					Type    string  `json:"type"`
					Command string  `json:"command"`
					Timeout float64 `json:"timeout"`
				} `json:"hooks"`
			} `json:"hooks"`
		}
		if err := json.Unmarshal(b, &file); err != nil {
			return nil, fmt.Errorf("%s: %w", path, err)
		}
		for _, group := range file.Hooks[event] {
			for _, h := range group.Hooks {
				if h.Type != "command" || strings.TrimSpace(h.Command) == "" {
					continue
				}
				out = append(out, settingsHook{Command: h.Command, Timeout: time.Duration(h.Timeout * float64(time.Second))})
			}
		}
	}

	return out, nil
}

// projectWorktree is one entry of git worktree list.
type projectWorktree struct{ Path, Branch string }

// listProjectWorktrees reads git worktree list in dir. The first entry is the main
// checkout, which a named lookup never returns.
func listProjectWorktrees(ctx context.Context, dir string) ([]projectWorktree, error) {
	c := exec.CommandContext(ctx, "git", "-C", dir, "worktree", "list", "--porcelain")
	b, err := c.Output()
	if err != nil {
		return nil, fmt.Errorf("git worktree list: %w", err)
	}
	var out []projectWorktree
	var cur *projectWorktree
	for line := range strings.SplitSeq(string(b), "\n") {
		line = strings.TrimRight(line, "\r")
		switch {
		case strings.HasPrefix(line, "worktree "):
			out = append(out, projectWorktree{Path: strings.TrimPrefix(line, "worktree ")})
			cur = &out[len(out)-1]
		case strings.HasPrefix(line, "branch ") && cur != nil:
			cur.Branch = strings.TrimPrefix(strings.TrimPrefix(line, "branch "), "refs/heads/")
		}
	}

	return out, nil
}

// findWorktree returns the folder of the linked worktree called name. A
// worktree whose claim names name and slug matches first, wherever the hook
// put it. Otherwise an unclaimed worktree whose folder or branch has that name
// matches. Another project entry can share the repository and use the same
// name, so a worktree in the worktree folder of the bridge matches only when
// it is the folder of slug and name.
func findWorktree(ctx context.Context, project, slug, name string) (string, bool, error) {
	list, err := listProjectWorktrees(ctx, project)
	if err != nil {
		return "", false, err
	}
	claims, err := readHookClaims(ctx, project)
	if err != nil {
		return "", false, err
	}
	for i, w := range list {
		if c, ok := claims[filepath.Clean(w.Path)]; i > 0 && ok && c.Name == name && c.Project == slug {
			return w.Path, true, nil
		}
	}
	for i, w := range list {
		if i == 0 || (filepath.Base(w.Path) != name && w.Branch != name) {
			continue
		}
		if _, claimed := claims[filepath.Clean(w.Path)]; claimed {
			continue
		}
		if inBridgeFolder(w.Path) && !isBridgeWorktree(w.Path, slug, name) {
			continue
		}

		return w.Path, true, nil
	}

	return "", false, nil
}

func validWorktreeName(name string) error {
	if name == "" || strings.HasPrefix(name, "-") || strings.HasPrefix(name, "/") || strings.Contains(name, "..") || strings.ContainsAny(name, " \t\n") {
		return fmt.Errorf("refusing the worktree name %q", name)
	}

	return nil
}

// runHookCommands runs each hook with sh -c in project, with the payload on
// stdin. Its stdout lines go to stderr, except the last non-empty line of the
// last hook that printed one, which is returned.
func runHookCommands(ctx context.Context, project string, hooks []settingsHook, payload []byte, stderr io.Writer) (string, error) {
	sh, err := exec.LookPath("sh")
	if err != nil {
		return "", errors.New("the hook adapter needs sh on the PATH")
	}
	last := ""
	for _, h := range hooks {
		timeout := h.Timeout
		if timeout <= 0 {
			timeout = defaultHookTimeout
		}
		hctx, cancel := context.WithTimeout(ctx, timeout)
		c := exec.CommandContext(hctx, sh, "-c", h.Command)
		c.Dir = project
		c.Env = append(os.Environ(), "CLAUDE_PROJECT_DIR="+project)
		c.Stdin = bytes.NewReader(payload)
		c.Stderr = stderr
		var stdout bytes.Buffer
		c.Stdout = &stdout
		err := c.Run()
		cancel()
		var lines []string
		for line := range strings.SplitSeq(stdout.String(), "\n") {
			if strings.TrimSpace(line) != "" {
				lines = append(lines, strings.TrimRight(line, "\r"))
			}
		}
		if len(lines) > 0 {
			for _, line := range lines[:len(lines)-1] {
				fmt.Fprintln(stderr, line)
			}
			last = lines[len(lines)-1]
		}
		if err != nil {
			return "", fmt.Errorf("hook %q failed: %w", h.Command, err)
		}
	}

	return last, nil
}

func hookPayload(event, project string, extra map[string]string) []byte {
	m := map[string]string{"hook_event_name": event, "cwd": project}
	for k, v := range extra {
		m[k] = v
	}
	b, _ := json.Marshal(m)

	return b
}

// runWorktreeCreate prints the folder of the worktree called name. An existing
// worktree of that name is reused. Otherwise the WorktreeCreate hook makes it.
func runWorktreeCreate(ctx context.Context, project, slug, name string, stdout, stderr io.Writer) error {
	if err := validWorktreeName(name); err != nil {
		return err
	}
	if path, ok, err := findWorktree(ctx, project, slug, name); err != nil {
		return err
	} else if ok {
		fmt.Fprintln(stdout, path)

		return nil
	}
	hooks, err := readSettingsHooks(project, hookCreateEvent)
	if err != nil {
		return err
	}
	if len(hooks) == 0 {
		return fmt.Errorf("no %s command hook in .claude/settings.json or .claude/settings.local.json of %s", hookCreateEvent, project)
	}
	path, err := runHookCommands(ctx, project, hooks, hookPayload(hookCreateEvent, project, map[string]string{"name": name}), stderr)
	if err != nil {
		return err
	}
	if path == "" {
		return fmt.Errorf("the %s hook printed no folder", hookCreateEvent)
	}
	if err := writeHookClaim(ctx, project, slug, name, path); err != nil {
		return err
	}
	fmt.Fprintln(stdout, path)

	return nil
}

// runWorktreeRemove runs the WorktreeRemove hook for the worktree called name,
// then removes the worktree with git when the hook left it. A hook may leave
// the removal to its caller, as Claude Code does it after the hook. A project
// with no such worktree, or with neither worktree hook, is a success, so one
// shared teardown entry can serve every project.
func runWorktreeRemove(ctx context.Context, project, slug, name string, _, stderr io.Writer) error {
	if err := validWorktreeName(name); err != nil {
		return err
	}
	path, ok, err := findWorktree(ctx, project, slug, name)
	if err != nil {
		return err
	}
	if !ok {
		return nil
	}
	if owned, err := ownsWorktree(ctx, project, path, slug, name); err != nil {
		return err
	} else if !owned {
		fmt.Fprintf(stderr, "skipping %s: worktree-create did not make it, and it is not the bridge worktree of this project\n", path)

		return nil
	}
	hooks, err := readSettingsHooks(project, hookRemoveEvent)
	if err != nil {
		return err
	}
	creates, err := readSettingsHooks(project, hookCreateEvent)
	if err != nil {
		return err
	}
	if len(hooks) == 0 && len(creates) == 0 {
		return nil
	}
	if len(hooks) > 0 {
		if _, err := runHookCommands(ctx, project, hooks, hookPayload(hookRemoveEvent, project, map[string]string{"worktree_path": path}), stderr); err != nil {
			return err
		}
	}
	if _, still, err := findWorktree(ctx, project, slug, name); err != nil || !still {
		return err
	}
	steps := [][]string{{"worktree", "prune"}}
	if _, err := os.Stat(path); err == nil {
		steps = append([][]string{{"worktree", "remove", "--force", path}}, steps...)
	}
	for _, args := range steps {
		c := exec.CommandContext(ctx, "git", append([]string{"-C", project}, args...)...)
		c.Stdout = stderr
		c.Stderr = stderr
		if err := c.Run(); err != nil {
			return fmt.Errorf("git %s: %w", strings.Join(args, " "), err)
		}
	}

	return nil
}

// worktreeGitDir is the per-worktree git dir of the worktree at path.
func worktreeGitDir(ctx context.Context, path string) (string, error) {
	b, err := exec.CommandContext(ctx, "git", "-C", path, "rev-parse", "--absolute-git-dir").Output()
	if err != nil {
		return "", fmt.Errorf("git rev-parse in %s: %w", path, err)
	}

	return strings.TrimSpace(string(b)), nil
}

// hookClaim is the content of hookClaimFile: the worktree name and the slug
// of the project that worktree-create ran for.
type hookClaim struct {
	Name    string `json:"name"`
	Project string `json:"project"`
}

// writeHookClaim marks the worktree at path, the folder that the hook
// printed, as made by worktree-create for name and the project slug. A
// relative path counts from project. A folder that is no linked worktree of
// project keeps no claim, so teardown leaves it.
func writeHookClaim(ctx context.Context, project, slug, name, path string) error {
	if !filepath.IsAbs(path) {
		path = filepath.Join(project, path)
	}
	list, err := listProjectWorktrees(ctx, project)
	if err != nil {
		return err
	}
	for i, w := range list {
		if i == 0 || resolvePath(w.Path) != resolvePath(path) {
			continue
		}
		dir, err := worktreeGitDir(ctx, w.Path)
		if err != nil {
			return err
		}
		b, err := json.Marshal(hookClaim{Name: name, Project: slug})
		if err != nil {
			return err
		}

		return os.WriteFile(filepath.Join(dir, hookClaimFile), append(b, '\n'), 0o600)
	}

	return nil
}

// readHookClaims maps the folder of each claimed worktree of project to its
// claim. It reads the admin dirs of git, which keep the claim of a deleted
// folder until a prune. A claim that does not parse counts as no claim, so
// teardown never removes that worktree.
func readHookClaims(ctx context.Context, project string) (map[string]hookClaim, error) {
	b, err := exec.CommandContext(ctx, "git", "-C", project, "rev-parse", "--path-format=absolute", "--git-common-dir").Output()
	if err != nil {
		return nil, fmt.Errorf("git rev-parse in %s: %w", project, err)
	}
	admin := filepath.Join(strings.TrimSpace(string(b)), "worktrees")
	entries, _ := os.ReadDir(admin)
	out := map[string]hookClaim{}
	for _, e := range entries {
		raw, err := os.ReadFile(filepath.Join(admin, e.Name(), hookClaimFile))
		if errors.Is(err, os.ErrNotExist) {
			continue
		}
		if err != nil {
			return nil, err
		}
		g, err := os.ReadFile(filepath.Join(admin, e.Name(), "gitdir"))
		if err != nil {
			continue
		}
		var c hookClaim
		if json.Unmarshal(raw, &c) != nil || c.Name == "" {
			continue
		}
		out[filepath.Dir(filepath.Clean(strings.TrimSpace(string(g))))] = c
	}

	return out, nil
}

// ownsWorktree reports whether teardown may remove the worktree called name
// at path: worktree-create claimed it for slug and name, or it is the bridge
// worktree of slug and name.
func ownsWorktree(ctx context.Context, project, path, slug, name string) (bool, error) {
	if isBridgeWorktree(path, slug, name) {
		return true, nil
	}
	claims, err := readHookClaims(ctx, project)
	if err != nil {
		return false, err
	}
	c, ok := claims[filepath.Clean(path)]

	return ok && c.Name == name && c.Project == slug, nil
}

// bridgeRoot is the worktree folder of the bridge, with its symlinks resolved.
func bridgeRoot() (string, bool) {
	d, err := config.Dir()
	if err != nil {
		return "", false
	}
	root := filepath.Join(d, "worktrees")
	if r, err := filepath.EvalSymlinks(root); err == nil {
		root = r
	}

	return root, true
}

func resolvePath(path string) string {
	if r, err := filepath.EvalSymlinks(path); err == nil {
		return r
	}

	return filepath.Clean(path)
}

// inBridgeFolder reports whether path lies in the worktree folder of the
// bridge, where only the bridge makes worktrees.
func inBridgeFolder(path string) bool {
	root, ok := bridgeRoot()
	if !ok {
		return false
	}
	rel, err := filepath.Rel(root, resolvePath(path))

	return err == nil && rel != "." && rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator))
}

// isBridgeWorktree reports whether path is the folder that the bridge makes
// for the worktree called name of the project slug.
func isBridgeWorktree(path, slug, name string) bool {
	if slug == "" || strings.ContainsAny(slug, `/\`) || strings.Contains(slug, "..") {
		return false
	}
	root, ok := bridgeRoot()

	return ok && resolvePath(path) == filepath.Join(root, slug, name)
}
