package cmd

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/harness/codex"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

const (
	// itemBranchPrefix starts the branch of an item worktree.
	itemBranchPrefix = "inbox-"
	// itemSessionGrace is how long a launched session may stay out of the
	// process list before the bridge counts it as ended, unless the bridge
	// saw it run.
	itemSessionGrace = 10 * time.Minute
	// itemSweepInterval is the time between two checks of the item sessions.
	itemSweepInterval = 30 * time.Second
	// codexIdle is how long a Codex session file may stay unchanged before the
	// session counts as ended. A Codex process carries no session id.
	codexIdle = 24 * time.Hour
	// itemDeadReadings is how many readings in a row must find a session gone.
	itemDeadReadings = 2
)

var itemFolder = regexp.MustCompile(`^item-([1-9][0-9]*)$`)

// itemRecord is what the bridge writes next to an item worktree, so a bridge
// that restarts knows which session to watch and where the project lives.
type itemRecord struct {
	SessionID  string    `json:"sessionId"`
	ProjectDir string    `json:"projectDir"`
	LaunchedAt time.Time `json:"launchedAt"`
}

// itemEntry is the state of one tracked item session.
type itemEntry struct {
	path       string
	projectDir string
	sessionID  string
	launchedAt time.Time
	// launching is true until the launch command returns.
	launching bool
	// seen is true once a reading found the session alive.
	seen bool
	// dead counts the readings in a row that found the session gone.
	dead int
}

// itemSessions makes the worktree of each session about an inbox item, and
// removes it when the session ends. Its zero value is not usable, so make one
// with newItemSessions.
type itemSessions struct {
	log *slog.Logger
	// readings reads how the sessions stand, as sessionLiveness.readings does.
	readings func(context.Context, []string) []api.InteractiveSessionReading
	// codex reports whether the session ran under Codex. It may be nil.
	codex func(sessionID string) bool
	now   func() time.Time

	mu      sync.Mutex
	tracked map[string]*itemEntry
}

func newItemSessions(log *slog.Logger, readings func(context.Context, []string) []api.InteractiveSessionReading) *itemSessions {
	if log == nil {
		log = slog.New(slog.DiscardHandler)
	}

	return &itemSessions{log: log, readings: readings, now: time.Now, tracked: map[string]*itemEntry{}}
}

// itemBranch is the branch of the worktree at path.
func itemBranch(path string) string {
	return itemBranchPrefix + strings.TrimPrefix(filepath.Base(path), "item-")
}

// checkItemPath refuses any path that is not <config>/worktrees/<slug>/item-<n>.
// A card worktree, the project folder and a path that climbs out all fail.
func checkItemPath(path string) error {
	root, err := config.Dir()
	if err != nil {
		return err
	}
	rel, err := filepath.Rel(filepath.Join(root, "worktrees"), filepath.Clean(path))
	if err != nil {
		return fmt.Errorf("%s is no item worktree", path)
	}
	parts := strings.Split(rel, string(filepath.Separator))
	if len(parts) != 2 || parts[0] == "" || parts[0] == ".." || !itemFolder.MatchString(parts[1]) {
		return fmt.Errorf("%s is no item worktree", path)
	}

	return nil
}

// sessionState is how a reading finds an item session.
type sessionState int

const (
	stateUnknown sessionState = iota
	stateAlive
	stateDead
)

// classify reads one reading of the session of e. A Codex session ends when
// its session file, or else its launch, is older than codexIdle. Any other
// session is dead when it is gone from the process list, but a session never
// seen alive gets itemSessionGrace to show up. A reading that cannot tell is
// unknown.
func (t *itemSessions) classify(e *itemEntry, r api.InteractiveSessionReading) sessionState {
	if t.codex != nil && t.codex(e.sessionID) {
		last := e.launchedAt
		if r.TranscriptAt != nil && r.TranscriptAt.After(last) {
			last = *r.TranscriptAt
		}
		if t.now().Sub(last) >= codexIdle {
			return stateDead
		}

		return stateUnknown
	}
	switch {
	case r.Alive == nil:
		return stateUnknown
	case *r.Alive:
		return stateAlive
	case !e.seen && t.now().Sub(e.launchedAt) < itemSessionGrace:
		return stateUnknown
	}

	return stateDead
}

// active reports whether the session of e may still run: it runs, nobody can
// tell, or it started too recently to show in the process list.
func (t *itemSessions) active(ctx context.Context, e *itemEntry) bool {
	var reading api.InteractiveSessionReading
	if t.readings != nil {
		if got := t.readings(ctx, []string{e.sessionID}); len(got) == 1 {
			reading = got[0]
		}
	}

	return t.classify(e, reading) != stateDead
}

// prepare makes the worktree of item in the project at projectDir, on the new
// branch inbox-<item> cut from the default branch, and tracks it as the folder
// of session sessionID. The caller calls finish when the launch command
// returns, or discard when the launch fails. A worktree of an earlier session
// that ended is replaced. One that may still run makes prepare fail. A failure
// leaves no folder, no record and no branch behind.
func (t *itemSessions) prepare(ctx context.Context, projectDir, slug string, item int, sessionID string) (string, error) {
	if item < 1 {
		return "", errors.New("the work request names no inbox item number")
	}
	path, err := config.ItemWorktreeDir(slug, item)
	if err != nil {
		return "", err
	}
	branch := itemBranchPrefix + strconv.Itoa(item)
	t.mu.Lock()
	defer t.mu.Unlock()
	worktreeMu.Lock()
	defer worktreeMu.Unlock()

	if prior := t.priorLocked(path); prior != nil && (prior.launching || t.active(ctx, prior)) {
		return "", fmt.Errorf("item %d already has a session open in %s", item, path)
	}
	if exists(path) || exists(path+".session") {
		if err := t.removeLocked(ctx, path, projectDir); err != nil {
			return "", fmt.Errorf("remove the worktree of the earlier session of item %d: %w", item, err)
		}
	}
	if _, err := git(ctx, projectDir, "rev-parse", "--verify", "--quiet", "refs/heads/"+branch); err == nil {
		return "", fmt.Errorf("the branch %s exists already in the project, and the bridge does not replace it", branch)
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return "", fmt.Errorf("create the worktree folder: %w", err)
	}
	if out, err := git(ctx, projectDir, "worktree", "prune"); err != nil {
		return "", gitFailure("git worktree prune", out, err)
	}
	start := defaultBranch(ctx, projectDir)
	if out, err := git(ctx, projectDir, "worktree", "add", "-b", branch, path, start); err != nil {
		_ = t.removeLocked(ctx, path, projectDir)

		return "", gitFailure("git worktree add of "+start, out, err)
	}
	entry := &itemEntry{path: path, projectDir: projectDir, sessionID: sessionID, launchedAt: t.now(), launching: true}
	if err := writeItemRecord(path+".session", itemRecord{SessionID: sessionID, ProjectDir: projectDir, LaunchedAt: entry.launchedAt}); err != nil {
		_ = t.removeLocked(ctx, path, projectDir)

		return "", fmt.Errorf("record the session of item %d: %w", item, err)
	}
	t.tracked[path] = entry

	return path, nil
}

// priorLocked is the tracked entry for path, or the one its record names.
func (t *itemSessions) priorLocked(path string) *itemEntry {
	if e, ok := t.tracked[path]; ok {
		return e
	}
	rec, err := readItemRecord(path + ".session")
	if err != nil {
		return nil
	}

	return &itemEntry{path: path, projectDir: rec.ProjectDir, sessionID: rec.SessionID, launchedAt: rec.LaunchedAt}
}

// finish marks the launch command of path as returned, so the sweep may watch
// the session.
func (t *itemSessions) finish(path string) {
	t.mu.Lock()
	defer t.mu.Unlock()
	if e, ok := t.tracked[path]; ok {
		e.launching = false
		e.launchedAt = t.now()
	}
}

// discard removes the worktree of a launch that failed.
func (t *itemSessions) discard(ctx context.Context, path string) {
	t.mu.Lock()
	defer t.mu.Unlock()
	worktreeMu.Lock()
	defer worktreeMu.Unlock()
	e, ok := t.tracked[path]
	if !ok {
		return
	}
	if err := t.removeLocked(ctx, path, e.projectDir); err != nil {
		t.log.Warn("item_worktree_not_removed", "path", path, "error", err.Error())
		// The sweep retries a removal that failed, so the launch is over and
		// its grace is spent.
		e.launching, e.launchedAt = false, time.Time{}
	}
}

// remove deletes the item worktree at path and its branch. The caller holds
// no lock.
func (t *itemSessions) remove(ctx context.Context, path, projectDir string) error {
	t.mu.Lock()
	defer t.mu.Unlock()
	worktreeMu.Lock()
	defer worktreeMu.Unlock()

	return t.removeLocked(ctx, path, projectDir)
}

// removeLocked deletes the worktree, the branch and the record of an item
// session, with no check for unsaved work. It refuses any path that is no item
// worktree. The caller holds mu and worktreeMu.
func (t *itemSessions) removeLocked(ctx context.Context, path, projectDir string) error {
	if err := checkItemPath(path); err != nil {
		return err
	}
	var problems []error
	if projectDir != "" {
		if out, err := git(ctx, projectDir, "worktree", "remove", "--force", path); err != nil && exists(path) {
			problems = append(problems, gitFailure("git worktree remove", out, err))
		}
	}
	if exists(path) {
		if err := os.RemoveAll(path); err != nil {
			problems = append(problems, err)
		}
	}
	if projectDir != "" {
		branch := itemBranch(path)
		if _, err := git(ctx, projectDir, "rev-parse", "--verify", "--quiet", "refs/heads/"+branch); err == nil {
			if out, err := git(ctx, projectDir, "branch", "-D", branch); err != nil {
				problems = append(problems, gitFailure("git branch -D", out, err))
			}
		}
		if out, err := git(ctx, projectDir, "worktree", "prune"); err != nil {
			problems = append(problems, gitFailure("git worktree prune", out, err))
		}
	}
	if len(problems) == 0 {
		if err := os.Remove(path + ".session"); err != nil && !errors.Is(err, fs.ErrNotExist) {
			problems = append(problems, err)
		}
	}
	if len(problems) == 0 {
		delete(t.tracked, path)
	}

	return errors.Join(problems...)
}

// sweep reads each tracked session once, and removes the worktree of a session
// that stayed gone for itemDeadReadings readings in a row. A reading that
// cannot tell, and a session still inside its grace, change nothing.
func (t *itemSessions) sweep(ctx context.Context) {
	if t.readings == nil {
		return
	}
	t.mu.Lock()
	var watched []*itemEntry
	var ids []string
	for _, e := range t.tracked {
		if !e.launching {
			watched, ids = append(watched, e), append(ids, e.sessionID)
		}
	}
	t.mu.Unlock()
	if len(ids) == 0 {
		return
	}
	got := t.readings(ctx, ids)
	if len(got) != len(ids) {
		return
	}

	t.mu.Lock()
	defer t.mu.Unlock()
	worktreeMu.Lock()
	defer worktreeMu.Unlock()
	for i, e := range watched {
		// A newer launch of the same item replaced the entry the reading was for.
		if t.tracked[e.path] != e || e.launching || e.sessionID != got[i].SessionID {
			continue
		}
		switch t.classify(e, got[i]) {
		case stateUnknown:
			e.dead = 0
		case stateAlive:
			e.seen, e.dead = true, 0
		case stateDead:
			e.dead++
			if e.dead < itemDeadReadings {
				continue
			}
			if err := t.removeLocked(ctx, e.path, e.projectDir); err != nil {
				t.log.Warn("item_worktree_not_removed", "path", e.path, "session_id", e.sessionID, "error", err.Error())

				continue
			}
			t.log.Info("item_worktree_removed", "path", e.path, "session_id", e.sessionID)
		}
	}
}

// adopt tracks the item sessions of a former bridge, which dirs maps from the
// project slug to the project folder. A folder with no record belongs to no
// session, so adopt removes it.
func (t *itemSessions) adopt(dirs map[string]string) {
	root, err := config.Dir()
	if err != nil {
		return
	}
	t.mu.Lock()
	defer t.mu.Unlock()
	worktreeMu.Lock()
	defer worktreeMu.Unlock()
	ctx := context.Background()
	for slug, projectDir := range dirs {
		entries, err := os.ReadDir(filepath.Join(root, "worktrees", slug))
		if err != nil {
			continue
		}
		for _, entry := range entries {
			name := entry.Name()
			name = strings.TrimSuffix(name, ".session")
			if !itemFolder.MatchString(name) {
				continue
			}
			path := filepath.Join(root, "worktrees", slug, name)
			if _, tracked := t.tracked[path]; tracked {
				continue
			}
			rec, err := readItemRecord(path + ".session")
			if err != nil {
				if rerr := t.removeLocked(ctx, path, projectDir); rerr != nil {
					t.log.Warn("item_worktree_not_removed", "path", path, "error", rerr.Error())
				}

				continue
			}
			t.tracked[path] = &itemEntry{path: path, projectDir: cmp.Or(rec.ProjectDir, projectDir), sessionID: rec.SessionID, launchedAt: rec.LaunchedAt}
		}
	}
}

func readItemRecord(path string) (itemRecord, error) {
	var rec itemRecord
	data, err := os.ReadFile(path)
	if err != nil {
		return rec, err
	}
	if err := json.Unmarshal(data, &rec); err != nil {
		return rec, err
	}
	if rec.SessionID == "" {
		return rec, errors.New("the record names no session")
	}

	return rec, nil
}

func writeItemRecord(path string, rec itemRecord) error {
	data, err := json.Marshal(rec)
	if err != nil {
		return err
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return err
	}

	return os.Rename(tmp, path)
}

// projectDirs maps each mapped project slug of set to its folder.
func projectDirs(set *rules.Set) map[string]string {
	dirs := map[string]string{}
	if set == nil {
		return dirs
	}
	for _, slug := range set.Projects() {
		dirs[slug] = set.Dir(slug)
	}

	return dirs
}

// run adopts the sessions of a former bridge, then sweeps until ctx ends.
func (t *itemSessions) run(ctx context.Context, dirs map[string]string, every time.Duration) {
	t.adopt(dirs)
	ticker := time.NewTicker(every)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			t.sweep(ctx)
		}
	}
}

// codexLaunched reports whether the bridge launched the session with Codex.
func codexLaunched(sessionID string) bool {
	threads, err := config.CodexThreadsDir()

	return err == nil && codex.Launched(threads, sessionID)
}

func exists(path string) bool {
	_, err := os.Lstat(path)

	return err == nil
}
