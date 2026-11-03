package cmd

import (
	"context"
	"strings"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
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
	}
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
