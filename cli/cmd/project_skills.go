package cmd

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/mcpjson"
	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/loupe-ac/loupe/cli/internal/skills"
	"github.com/loupe-ac/loupe/cli/internal/stream"
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
	// with no dir has an empty store. cliVersion is the version
	// the minCli of a package is compared with, and "" is a development
	// build that meets every minCli.
	dir        string
	cliVersion string
	// storeErr says why the store could not be set up, for the worker that
	// then finds it empty.
	storeErr error
	// install fills the store. A nil one unpacks the starting copy.
	install func(dir string) ([]string, error)
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
	// failed holds the result of the last update that failed, for each package.
	// An update that succeeds clears it, and so does another version in the store.
	failed map[string]skills.Result
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
// folder for the workers that start after it. A failure is logged, the store
// keeps what it held, and a release worker that finds the store empty fails with
// the reason.
func (r *router) startSkills(dir string) {
	r.skills.dir = dir
	install := r.skills.install
	if install == nil {
		install = skills.Install
	}
	written, err := install(dir)
	if err != nil {
		r.skills.storeErr = err
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
// fails when the project names a package the store cannot serve, when a release
// build has an empty store, or when the record of the session cannot be written,
// and the run then does not start. The caller calls releasePackages when the
// worker ended.
func (r *router) givePackages(spec *workerSpec, p pending) error {
	s := &r.skills
	if spec.harnessName != "" && spec.harnessName != rules.HarnessClaudeCode {
		return nil
	}
	var held []string
	if s.dir != "" {
		held = skills.Held(s.dir)
	}
	if len(held) == 0 {
		// A development build holds no starting copy. The worker then uses
		// the plugin its account installed.
		if s.cliVersion == "" {
			return nil
		}

		return s.emptyStoreError()
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

// emptyStoreError is the failure of a release worker that has no packages. An
// account has no fallback plugin or server, so the worker could not do its work.
func (s *skillState) emptyStoreError() error {
	const fix = "fix that and restart the bridge"
	if s.storeErr != nil {
		return fmt.Errorf("the skill store holds no packages: %w; %s", s.storeErr, fix)
	}

	return errors.New("the skill store holds no packages; " + fix)
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

// packageNames lists the packages a bridge keeps: every package held, and every
// package a project names. The list is sorted and has each name once. A folder
// of the store that no package name fits, such as one a person made, is not a
// package.
func packageNames(held []string, sites []api.Site) []string {
	names := slices.DeleteFunc(slices.Clone(held), func(name string) bool { return !skills.ValidName(name) })
	for _, site := range sites {
		names = append(names, site.PackagesIn(held)...)
	}
	slices.Sort(names)

	return slices.Compact(names)
}

// skillNames lists the packages the updater keeps current. A router with no
// store has none.
func (r *router) skillNames() []string {
	s := &r.skills
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.dir == "" {
		return nil
	}

	return packageNames(skills.Held(s.dir), s.sites)
}

// recordSkillResults keeps the reason of each package that failed to update.
func (r *router) recordSkillResults(results []skills.Result) {
	s := &r.skills
	s.mu.Lock()
	defer s.mu.Unlock()
	s.failed = map[string]skills.Result{}
	for _, res := range results {
		if res.State == skills.StateFailed {
			s.failed[res.Package] = res
		}
	}
}

// The server refuses a whole heartbeat that holds more rows than maxSkillReports,
// a blank version, or a reason above maxSkillReason bytes. noSkillVersion stands
// for a package with no usable version in the store.
const (
	maxSkillReports = 20
	maxSkillReason  = 500
	noSkillVersion  = "none"
)

// skillReports gives the heartbeat one row for each package. The version comes
// from the store, so it is right after any install, `loupe update` included. A
// package that the last update failed for is failed, with that reason, until the
// store holds another version. Another process can install that version, so only
// the store shows it. A development build with an empty store reports nothing, because its workers use
// the plugin their account installed.
func (r *router) skillReports() []api.SkillReport {
	names := r.skillNames()
	s := &r.skills
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.dir == "" || (s.cliVersion == "" && len(skills.Held(s.dir)) == 0) {
		return nil
	}
	reports := make([]api.SkillReport, 0, min(len(names), maxSkillReports))
	for _, name := range names[:min(len(names), maxSkillReports)] {
		res := skills.Status(s.dir, s.cliVersion, name)
		if failure, ok := s.failed[name]; ok && failure.Version == res.Version {
			res.State, res.Reason = skills.StateFailed, failure.Reason
		}
		reports = append(reports, api.SkillReport{
			Package: res.Package,
			Version: cmp.Or(res.Version, noSkillVersion),
			State:   res.State,
			Reason:  stream.Cut(res.Reason, maxSkillReason),
		})
	}

	return reports
}
