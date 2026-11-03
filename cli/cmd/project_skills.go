package cmd

import (
	"context"
	"encoding/json"
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

	mu      sync.Mutex
	sites   []api.Site
	failing bool
	// started counts the reads begun, and kept names the newest read whose
	// answer the state holds.
	started, kept uint64
	// holds names the packages of each run from its pick until its worker
	// ends. A prune takes mu for its whole length, so a pick never lands on a
	// folder that the prune deletes.
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
// the versions of each run in holds, each version the record of a live worker
// names, and each version a new worker of a project would get. Only the record
// reaches a worker that a former image started.
func (r *router) pruneSkills(now time.Time) {
	s := &r.skills
	if s.dir == "" {
		return
	}
	r.mu.Lock()
	sessions := make([]string, 0, len(r.live))
	for _, run := range r.live {
		sessions = append(sessions, run.p.spec.sessionID)
	}
	r.mu.Unlock()

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
	for _, session := range sessions {
		for pkg, version := range skills.ReadRecord(s.dir, session) {
			keep[pkg+"/"+version] = true
		}
	}
	for _, gone := range skills.Prune(s.dir, now, func(pkg, version string) bool { return keep[pkg+"/"+version] }) {
		r.log.Info("skill_version_deleted", "version", gone)
	}
}

// givePackages picks the skill packages of a Claude Code worker and the loupe
// MCP server it starts with. A resume uses the versions its session started
// with while the store still holds them, and the current ones otherwise. It
// fails when the project names a package the store cannot serve, and the run
// then does not start. The caller calls releasePackages when the worker ended.
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
		return fmt.Errorf("%w; run `loupe update`", err)
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
// records them for the session. A record that fails to write only makes a
// resume take the current versions, so the run starts.
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
			return nil, err
		}
	}
	if s.holds == nil {
		s.holds = map[string][]skills.Pick{}
	}
	s.holds[runID] = picks
	if err := skills.WriteRecord(s.dir, spec.sessionID, picks); err != nil {
		r.log.Warn("skill_record_not_written", "session_id", spec.sessionID, "error", err.Error())
	}

	return picks, nil
}

// releasePackages ends the hold of a run.
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
