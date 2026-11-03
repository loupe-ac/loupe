package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/mcpjson"
	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/loupe-ac/loupe/cli/internal/skills"
)

// skillsInterval is the time between two reads of the project list.
const skillsInterval = time.Hour

// skillsTimeout bounds one read of the project list.
const skillsTimeout = 30 * time.Second

// launchGrace is how long a new record counts as live with no process that names
// its session. A launcher can return before the terminal starts the program.
const launchGrace = time.Hour

// skillState is what the router keeps about the skill packages of each
// project. mu guards every field.
type skillState struct {
	// list reads the project list. A nil one reads nothing, and every
	// project keeps the fallback.
	list func(ctx context.Context) ([]api.Site, error)
	// dir is the config folder whose skills folder is the store. A router
	// with no dir gives its workers no packages. cliVersion is the version
	// the minCli of a package is compared with, and "" is a development
	// build that meets every minCli.
	dir        string
	cliVersion string
	// exe is the path a worker starts loupe from for the MCP server. A nil
	// one uses the running binary.
	exe func() (string, error)
	// after stands in for time.After in a test. A nil one uses the real one.
	after func(time.Duration) <-chan time.Time
	// processes lists the command lines of all processes. A nil one reads the
	// real list.
	processes func(context.Context) (string, error)

	mu      sync.Mutex
	sites   []api.Site
	failing bool
	// started counts the reads begun, and kept names the newest read whose
	// answer the state holds.
	started, kept uint64
	// holds names the packages of each run from its pick until its worker
	// ends, or until the launcher of an interactive session returns. The record
	// of the session then keeps them. A prune takes mu for its whole length, so
	// a pick never lands on a folder that the prune deletes.
	holds map[string][]skills.Pick
}

// loadSkills reads the project list and keeps the answer, unless a read that
// began later already answered. A failed read keeps the last answer, so a bridge
// that lost the network still starts its workers with the packages it knew. It
// logs one failure for each streak.
func (r *router) loadSkills(ctx context.Context) {
	s := &r.skills
	if s.list == nil {
		return
	}
	s.mu.Lock()
	s.started++
	read := s.started
	s.mu.Unlock()

	ctx, cancel := context.WithTimeout(ctx, skillsTimeout)
	defer cancel()
	sites, err := s.list(ctx)

	s.mu.Lock()
	defer s.mu.Unlock()
	if read < s.kept {
		return
	}
	if err != nil {
		if !s.failing {
			r.log.Warn("skill_packages_read_failed", "error", err.Error(), "kept", len(s.sites))
		}
		s.failing = true

		return
	}
	if s.failing {
		r.log.Info("skill_packages_read", "projects", len(sites))
	}
	s.failing, s.sites, s.kept = false, sites, read
}

// skillLoop reads the project list each skillsInterval, until ctx ends. The
// start of the bridge makes the first read, before any work can dispatch.
func (r *router) skillLoop(ctx context.Context) {
	after := r.skills.after
	if after == nil {
		after = time.After
	}
	for {
		select {
		case <-ctx.Done():
			return
		case <-after(skillsInterval):
		}
		r.loadSkills(ctx)
		r.pruneSkills(time.Now())
	}
}

// startSkills unpacks the starting copy into the store, and keeps the store
// folder for the workers that start after it. A failure is logged, and the
// store keeps what it held.
func (r *router) startSkills(dir string) {
	r.skills.dir = dir
	written, err := skills.Install(dir)
	if err != nil {
		r.log.Warn("skill_store_install_failed", "error", err.Error())

		return
	}
	if len(written) > 0 {
		r.log.Info("skill_store_installed", "packages", strings.Join(written, ","))
	}
}

// pruneSkills deletes the versions that a newer one replaced long ago. It keeps
// the versions of each run in holds, each version a new worker of a project would
// get, and each version the record of a live session names. Only the record
// reaches an interactive session, which outlives its launcher and can outlive the
// bridge, and a worker that a former image started. A process that names the
// session makes it live, so a pass that cannot list the processes deletes nothing.
func (r *router) pruneSkills(now time.Time) {
	s := &r.skills
	if s.dir == "" {
		return
	}
	running, err := r.runningSessions()
	if err != nil {
		r.log.Warn("skill_prune_skipped", "error", err.Error())

		return
	}

	s.mu.Lock()
	defer s.mu.Unlock()
	names := skills.Held(s.dir)
	lists := [][]string{names}
	for _, site := range s.sites {
		lists = append(lists, site.PackagesIn(names))
	}
	keep := skills.Current(s.dir, s.cliVersion, lists)
	for _, picks := range s.holds {
		for _, pick := range picks {
			keep[pick.Package+"/"+pick.Version] = true
		}
	}
	for session, at := range skills.Records(s.dir) {
		if !running[session] && now.Sub(at) >= launchGrace {
			continue
		}
		for pkg, version := range skills.ReadRecord(s.dir, session) {
			keep[pkg+"/"+version] = true
		}
	}
	gone := skills.Prune(s.dir, now, func(session string) bool { return running[session] }, func(pkg, version string) bool { return keep[pkg+"/"+version] })
	for _, version := range gone {
		r.log.Info("skill_version_deleted", "version", version)
	}
}

// runningSessions names each session of a live run and each session that a
// process names. An empty process list is an error, because it says nothing about
// what runs.
func (r *router) runningSessions() (map[string]bool, error) {
	list := r.skills.processes
	if list == nil {
		list = listProcesses
	}
	out, err := list(context.Background())
	if err != nil {
		return nil, fmt.Errorf("list the processes: %w", err)
	}
	if strings.TrimSpace(out) == "" {
		return nil, errors.New("the process list is empty")
	}
	running := map[string]bool{}
	for _, line := range strings.Split(out, "\n") {
		for _, session := range sessionsOf(line) {
			running[session] = true
		}
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	for _, run := range r.live {
		running[run.p.spec.sessionID] = true
	}

	return running, nil
}

// givePackages picks the skill packages of a Claude Code worker and the loupe
// MCP server it starts with. A resume uses the versions its session started
// with while the store still holds them, and the current ones otherwise. It
// fails when the project names a package the store cannot serve, or when the
// record of the session cannot be written, and the run then does not start. The
// caller calls releasePackages when the worker ended.
func (r *router) givePackages(spec *workerSpec, p pending) error {
	s := &r.skills
	if s.dir == "" || (spec.harnessName != "" && spec.harnessName != rules.HarnessClaudeCode) {
		return nil
	}
	held := skills.Held(s.dir)
	if len(held) == 0 {
		// A development build holds no starting copy. The worker then uses
		// the plugin its account installed.
		return nil
	}
	picks, err := r.pickPackages(*spec, p.runID, r.projectPackages(p.project, held))
	if err != nil {
		return err
	}
	exe := s.exe
	if exe == nil {
		exe = os.Executable
	}
	path, err := exe()
	if err != nil {
		return fmt.Errorf("find loupe for the MCP server: %w", err)
	}
	entry := map[string]any{"mcpServers": map[string]any{mcpjson.ServerKey: map[string]any{"command": path, "args": []string{"mcp"}}}}
	mcp, err := json.Marshal(entry)
	if err != nil {
		return err
	}
	spec.packages, spec.mcpConfig = picks, string(mcp)

	return nil
}

// pickPackages chooses the versions of one worker, holds them for the run and
// records them for the session. It fails when the record cannot be written,
// because only the record keeps the versions of a session that outlives its
// launcher or its bridge.
func (r *router) pickPackages(spec workerSpec, runID string, wanted []string) ([]skills.Pick, error) {
	s := &r.skills
	s.mu.Lock()
	defer s.mu.Unlock()
	var picks []skills.Pick
	if spec.resume {
		if exact, ok := skills.Exact(s.dir, skills.ReadRecord(s.dir, spec.sessionID)); ok {
			picks = exact
		} else {
			r.log.Info("skill_packages_current_on_resume", "session_id", spec.sessionID)
		}
	}
	if picks == nil {
		var err error
		if picks, err = skills.Resolve(s.dir, s.cliVersion, wanted); err != nil {
			return nil, fmt.Errorf("%w; run `loupe update`", err)
		}
	}
	if err := skills.WriteRecord(s.dir, spec.sessionID, picks); err != nil {
		return nil, fmt.Errorf("record the skill packages of session %s: %w", spec.sessionID, err)
	}
	if s.holds == nil {
		s.holds = map[string][]skills.Pick{}
	}
	s.holds[runID] = picks

	return picks, nil
}

// releasePackages ends the hold of a run. The record of an interactive session
// keeps its versions after that.
func (r *router) releasePackages(runID string) {
	s := &r.skills
	s.mu.Lock()
	defer s.mu.Unlock()
	delete(s.holds, runID)
}

// projectSite is the last answer of the app for the project that handle
// names, by id or by slug. It reports false for a project the app has not
// listed yet, and the caller then takes the fallback of api.Site.
func (r *router) projectSite(handle string) (api.Site, bool) {
	s := &r.skills
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, site := range s.sites {
		if strings.EqualFold(site.ID, handle) || (site.Slug != "" && strings.EqualFold(site.Slug, handle)) {
			return site, true
		}
	}

	return api.Site{}, false
}

// projectPackages returns the skill packages a worker of the project needs,
// from the packages held. A project the app has not listed gets every package
// held.
func (r *router) projectPackages(handle string, held []string) []string {
	site, _ := r.projectSite(handle)

	return site.PackagesIn(held)
}
