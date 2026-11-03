package cmd

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
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

// pruneSkills deletes the versions that a newer one replaced long ago, except
// the ones a live run holds.
func (r *router) pruneSkills(now time.Time) {
	if r.skills.dir == "" {
		return
	}
	r.mu.Lock()
	held := map[string]bool{}
	for _, run := range r.live {
		for _, pick := range run.p.spec.packages {
			held[pick.Package+"/"+pick.Version] = true
		}
	}
	r.mu.Unlock()
	for _, gone := range skills.Prune(r.skills.dir, now, func(pkg, version string) bool { return held[pkg+"/"+version] }) {
		r.log.Info("skill_version_deleted", "version", gone)
	}
}

// givePackages picks the skill packages of a Claude Code worker and the loupe
// MCP server it starts with. A resume uses the versions its run started with
// while the store still holds them, and the current ones otherwise. It fails
// when the project names a package the store cannot serve, and the run then
// does not start.
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
	var picks []skills.Pick
	if spec.resume {
		if runs, err := config.RunsDir(); err == nil && p.continues != "" {
			if exact, ok := skills.Exact(s.dir, skills.ReadRecord(filepath.Join(runs, p.continues))); ok {
				picks = exact
			}
		}
		if picks == nil {
			r.log.Info("skill_packages_current_on_resume", "session_id", spec.sessionID)
		}
	}
	if picks == nil {
		var err error
		if picks, err = skills.Resolve(s.dir, s.cliVersion, r.projectPackages(p.project, held)); err != nil {
			return fmt.Errorf("%w; run `loupe update`", err)
		}
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
