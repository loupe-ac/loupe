package cmd

import (
	"context"
	"log/slog"
	"maps"
	"slices"
	"time"

	harn "github.com/loupe-ac/loupe/cli/internal/harness"
)

// accountRecheckInterval is the time between two checks of the failing accounts.
const accountRecheckInterval = 5 * time.Minute

// accountRecheckSoon is the first wait after a start check that ran out of time.
const accountRecheckSoon = 30 * time.Second

// anyTemporary says whether a later check can pass a problem of results.
func anyTemporary(results []accountResult) bool {
	return slices.ContainsFunc(results, func(a accountResult) bool {
		return slices.ContainsFunc(a.problems, func(p harn.Problem) bool { return p.Temporary })
	})
}

// recheckLoop checks the failing accounts again, until ctx ends. Each wait
// starts after the last check ends.
func (r *router) recheckLoop(ctx context.Context) {
	after := r.recheckAfter
	if after == nil {
		after = time.After
	}
	wait := accountRecheckInterval
	if r.recheckSoon {
		wait = accountRecheckSoon
	}
	for {
		select {
		case <-ctx.Done():
			return
		case <-after(wait):
		}
		r.recheckAccounts(ctx)
		wait = accountRecheckInterval
	}
}

// recheckAccounts heals what now passes, and reports a change. It takes no
// reloadMu, so it drops a result that a reload overtook. The set holds the
// last logged reasons, so every writer of its problems must log them.
func (r *router) recheckAccounts(ctx context.Context) bool {
	set := r.rules()
	failing := slices.Concat(slices.Collect(maps.Keys(set.AccountsOff())), slices.Collect(maps.Keys(set.ProjectsOff())))
	if len(failing) == 0 {
		return false
	}
	slices.Sort(failing)
	check := r.recheck
	if check == nil {
		check = r.checkNamedAccounts
	}
	results := check(ctx, set, slices.Compact(failing))

	r.mu.Lock()
	// An ended context fails a login check as a logged-out account does.
	if ctx.Err() != nil || r.shut() || r.reloading || r.rules() != set {
		r.mu.Unlock()

		return false
	}
	off, projects := set.AccountsOff(), set.ProjectsOff()
	nextOff, nextProjects := healOnly(off, projects, results)
	if maps.Equal(off, nextOff) && maps.EqualFunc(projects, nextProjects, maps.Equal) {
		r.mu.Unlock()

		return false
	}
	// The projects go first, so an account that heals shows no failing project
	// as ready in between.
	set.SetProjectProblems(nextProjects)
	set.SetAccountProblems(nextOff)
	heartbeat := r.heartbeat
	r.mu.Unlock()

	logRecheck(r.log, results, off, projects, nextOff, nextProjects)
	// A reload can swap the set during a send, and the old body must not stay.
	for heartbeat != nil {
		current := r.rules()
		heartbeat.setBody(heartbeatBody(current))
		if r.rules() == current {
			break
		}
	}

	return true
}

// logRecheck logs each part that healed, and each part whose reason changed.
func logRecheck(log *slog.Logger, results []accountResult, off map[string]string, projects map[string]map[string]string, nextOff map[string]string, nextProjects map[string]map[string]string) {
	var changed []accountResult
	for _, a := range results {
		attrs := []any{"account", a.name, "harness", a.harness, "unused", !a.used}
		if off[a.name] != "" && nextOff[a.name] == "" {
			log.Info("account_recovered", attrs...)
		}
		for _, slug := range slices.Sorted(maps.Keys(projects[a.name])) {
			if nextProjects[a.name][slug] == "" {
				log.Info("project_recovered", append(slices.Clone(attrs), "project", slug)...)
			}
		}
		a.problems = slices.DeleteFunc(slices.Clone(a.problems), func(p harn.Problem) bool {
			before, after := off[a.name], nextOff[a.name]
			if p.Project != "" {
				before, after = projects[a.name][p.Project], nextProjects[a.name][p.Project]
			}

			return after == "" || after == before
		})
		changed = append(changed, a)
	}
	warnAccountsOff(log, changed)
}
