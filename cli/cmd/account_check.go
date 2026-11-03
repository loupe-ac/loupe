package cmd

import (
	"cmp"
	"context"
	"log/slog"
	"maps"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	harn "github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// accountCheckTimeout bounds the checks of all the accounts of a set.
const accountCheckTimeout = 60 * time.Second

// maxAccountReason is the longest reason the server takes. A longer one
// fails the whole heartbeat.
const maxAccountReason = 200

// accountResult is the check of one account, which is ready when it has no
// problem.
type accountResult struct {
	name, harness string
	// used is false for an account no rule runs on.
	used     bool
	problems []harn.Problem
}

// reason joins the reasons of the problems of the whole account, and is ""
// for an account that is ready or fails for single projects alone.
func (a accountResult) reason() string {
	return a.join(func(p harn.Problem) string { return p.Reason })
}

func (a accountResult) detail() string {
	return a.join(func(p harn.Problem) string { return p.Detail })
}

func (a accountResult) join(field func(harn.Problem) string) string {
	parts := make([]string, 0, len(a.problems))
	for _, p := range a.problems {
		if p.Project == "" {
			parts = append(parts, field(p))
		}
	}

	return strings.Join(parts, "; ")
}

// projectReasons maps each project the account failed for to the reasons of
// its problems.
func (a accountResult) projectReasons() map[string]string {
	parts := map[string][]string{}
	for _, p := range a.problems {
		if p.Project != "" {
			parts[p.Project] = append(parts[p.Project], p.Reason)
		}
	}
	out := make(map[string]string, len(parts))
	for slug, reasons := range parts {
		out[slug] = strings.Join(reasons, "; ")
	}

	return out
}

// checkAccounts checks the accounts of the set before the bridge knows the
// project list, so a project needs every package the store holds.
func checkAccounts(ctx context.Context, set *rules.Set) []accountResult {
	return checkAccountsWith(ctx, set, nil)
}

// checkAccountsWith checks each account that the set declares with its harness,
// in every project of the set, with the environment a worker of the account gets.
// sites is the project list that tells a Codex check which plugins a project
// needs.
func checkAccountsWith(ctx context.Context, set *rules.Set, sites []api.Site) []accountResult {
	ctx, cancel := context.WithTimeout(ctx, accountCheckTimeout)
	defer cancel()
	projects := map[string]string{}
	for _, slug := range set.Projects() {
		projects[slug] = set.Dir(slug)
	}
	names := set.DeclaredAccounts()
	used := set.UsedAccounts()
	plugins := checkInputs(slices.Collect(maps.Keys(projects)), sites)
	// Each account runs in its own goroutine, so a slow one cannot use up the
	// time of the next.
	out := make([]accountResult, len(names))
	var wg sync.WaitGroup
	for i, name := range names {
		wg.Go(func() {
			out[i] = checkAccount(ctx, set, name, projects, plugins)
			out[i].used = slices.Contains(used, name)
		})
	}
	wg.Wait()

	return out
}

func checkAccount(ctx context.Context, set *rules.Set, name string, projects map[string]string, plugins codexCheckInputs) accountResult {
	run, _ := set.Account(name, "")
	res := accountResult{name: name, harness: harnessNameOf(run)}
	h, err := harnessByName(run.Harness, run.ConfigDir, run.Profile)
	if err != nil {
		res.problems = []harn.Problem{{Reason: "unknown harness", Detail: err.Error()}}

		return res
	}
	env, err := workerSpec{envFiles: run.EnvFiles, harnessName: run.Harness, configDir: run.ConfigDir}.accountEnv()
	if err != nil {
		res.problems = []harn.Problem{{Reason: "env file does not read", Detail: err.Error()}}

		return res
	}
	res.problems = h.Check(ctx, harn.CheckSpec{Account: name, ConfigDir: run.ConfigDir, Env: env, Projects: projects, Packages: plugins.packages, MissingPackages: plugins.missing, LegacySkills: plugins.legacy})

	return res
}

// harnessNameOf names the harness of an account, and Claude Code when the
// account names none.
func harnessNameOf(run rules.RunSettings) string {
	return cmp.Or(run.Harness, defaultHarness().Name())
}

// accountsOff maps each failing account to its reason. An account that no rule
// runs on turns nothing off, so it is safe to list it for the heartbeat.
func accountsOff(results []accountResult) map[string]string {
	off := map[string]string{}
	for _, a := range results {
		if reason := a.reason(); reason != "" {
			off[a.name] = reason
		}
	}

	return off
}

// projectsOff maps each account to the projects it failed for, and each of
// them to the reason. An account with no failed project has no entry.
func projectsOff(results []accountResult) map[string]map[string]string {
	off := map[string]map[string]string{}
	for _, a := range results {
		if projects := a.projectReasons(); len(projects) > 0 {
			off[a.name] = projects
		}
	}

	return off
}

// warnAccountsOff logs each failing account.
func warnAccountsOff(log *slog.Logger, results []accountResult) {
	for _, a := range results {
		if a.reason() != "" {
			log.Warn("account_failed", "account", a.name, "harness", a.harness, "unused", !a.used, "reason", a.reason(), "detail", a.detail())
		}
		for _, p := range a.problems {
			if p.Project != "" {
				log.Warn("project_failed", "account", a.name, "harness", a.harness, "unused", !a.used, "project", p.Project, "reason", p.Reason, "detail", p.Detail)
			}
		}
	}
}

// accountReports gives a row for each account the set declares, and nil when no
// check ran on the set.
func accountReports(set *rules.Set) []api.AccountReport {
	off, projectsOff := set.AccountsOff(), set.ProjectsOff()
	if off == nil {
		return nil
	}
	reports := []api.AccountReport{}
	used := set.UsedAccounts()
	for _, name := range set.DeclaredAccounts() {
		run, _ := set.Account(name, "")
		row := api.AccountReport{Name: name, Harness: harnessNameOf(run), State: api.AccountReady, Used: slices.Contains(used, name), Kinds: nonNil(set.AccountKinds(name)), StopAt: set.StopAt(name), AppPrompts: set.RunsAppPrompts(name)}
		if reason := off[name]; reason != "" {
			row.State, row.Reason = api.AccountFailing, cutReason(reason)
		}
		row.FailedProjects = failedProjects(set, projectsOff[name])
		reports = append(reports, row)
	}

	return reports
}

// failedProjects lists the projects of m, which maps a slug to a reason, by
// project id, in slug order. A slug without an id leaves the list.
func failedProjects(set *rules.Set, m map[string]string) []api.FailedProject {
	var out []api.FailedProject
	for _, slug := range slices.Sorted(maps.Keys(m)) {
		if id := set.ProjectID(slug); id != "" {
			out = append(out, api.FailedProject{ProjectID: id, Reason: cutReason(m[slug])})
		}
	}

	return out
}

// cutReason keeps a reason within maxAccountReason characters.
func cutReason(reason string) string {
	runes := []rune(reason)
	if len(runes) <= maxAccountReason {
		return reason
	}

	return string(runes[:maxAccountReason-1]) + "…"
}

// nonNil turns a nil slice into an empty one, so a JSON field shows as [] and not null.
func nonNil(kinds []string) []string {
	if kinds == nil {
		return []string{}
	}

	return kinds
}
