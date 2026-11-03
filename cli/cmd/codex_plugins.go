package cmd

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/envfile"
	"github.com/loupe-ac/loupe/cli/internal/harness/codex"
	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/loupe-ac/loupe/cli/internal/skills"
	"github.com/loupe-ac/loupe/cli/internal/stream"
)

// pluginSyncTimeout bounds one pass over the Codex homes of the rule file.
const pluginSyncTimeout = 10 * time.Minute

// The state of the Loupe plugins in one Codex home.
const (
	pluginsCurrent = "current"
	pluginsWaiting = "waiting"
	pluginsFailed  = "failed"
)

// maxPluginReason bounds the reason a state keeps.
const maxPluginReason = 300

// codexHomeReport is what the bridge says about the plugins of one Codex home.
type codexHomeReport struct {
	Home   string `json:"home"`
	State  string `json:"state"`
	Reason string `json:"reason,omitempty"`
}

// pluginHome is a Codex home of the rule file, with the environment its account
// gives codex. err says the account environment did not read.
type pluginHome struct {
	home string
	env  []string
	err  error
}

// resolveCodexHome is the Codex home of an account: its own folder, else the
// default one.
func resolveCodexHome(configDir string) string {
	if configDir != "" {
		return filepath.Clean(configDir)
	}
	home, err := codex.Home()
	if err != nil {
		return ""
	}

	return filepath.Clean(home)
}

// pluginHomes lists the Codex homes of the rule file, each once.
func pluginHomes(set *rules.Set) []pluginHome {
	if set == nil {
		return nil
	}
	var homes []pluginHome
	for _, name := range set.DeclaredAccounts() {
		run, _ := set.Account(name, "")
		if run.Harness != rules.HarnessCodex {
			continue
		}
		home := resolveCodexHome(run.ConfigDir)
		if home == "" || slices.ContainsFunc(homes, func(h pluginHome) bool { return h.home == home }) {
			continue
		}
		env, err := workerSpec{envFiles: run.EnvFiles, harnessName: run.Harness, configDir: run.ConfigDir}.accountEnv()
		homes = append(homes, pluginHome{home: home, env: codex.HomeEnv(envfile.Overlay(os.Environ(), env), home), err: err})
	}

	return homes
}

// binary is the codex program of the home.
func (h pluginHome) binary() (string, error) {
	return envfile.LookPath("codex", h.env, "")
}

// syncCodexPlugins brings the Loupe plugins of each Codex home of the rule file
// up to the store. It touches a home only when the user home still holds the old
// skill folders, or when the home holds the plugins already. It installs in a
// home only when no worker runs on it, and the next pass retries a home that
// waits. After the last home, it moves the old skill folders away.
func (r *router) syncCodexPlugins(ctx context.Context) {
	s := &r.skills
	if s.dir == "" {
		return
	}
	s.syncMu.Lock()
	defer s.syncMu.Unlock()
	ctx, cancel := context.WithTimeout(ctx, pluginSyncTimeout)
	defer cancel()

	homes := pluginHomes(r.rules())
	if len(homes) == 0 {
		s.setCodexHomes(nil)

		return
	}
	picks, err := skills.BuildMarketplace(s.dir, s.cliVersion)
	if err != nil {
		r.log.Warn("codex_marketplace_failed", "error", err.Error())
		s.setCodexHomes(nil)

		return
	}
	if len(picks) == 0 {
		s.setCodexHomes(nil)

		return
	}
	legacy := len(codex.LegacySkillFolders()) > 0

	var reports []codexHomeReport
	allCurrent := true
	for _, h := range homes {
		rep := r.syncHome(ctx, h, picks, legacy)
		if rep == nil {
			continue
		}
		reports = append(reports, *rep)
		allCurrent = allCurrent && rep.State == pluginsCurrent
		r.logPluginState(*rep)
	}
	s.setCodexHomes(reports)

	if legacy && allCurrent && len(reports) == len(homes) {
		r.moveLegacySkills()
	}
}

// syncHome brings one home up to date. It returns nil for a home the bridge
// leaves alone.
func (r *router) syncHome(ctx context.Context, h pluginHome, picks []skills.Pick, legacy bool) *codexHomeReport {
	fail := func(err error) *codexHomeReport {
		if !legacy {
			return nil
		}

		return &codexHomeReport{Home: h.home, State: pluginsFailed, Reason: stream.Cut(err.Error(), maxPluginReason)}
	}
	if h.err != nil {
		return fail(h.err)
	}
	binary, err := h.binary()
	if err != nil {
		return fail(err)
	}
	have, err := codex.ListPlugins(ctx, binary, h.env)
	if err != nil {
		return fail(err)
	}
	if !legacy && len(have) == 0 {
		return nil
	}
	todo := codex.PluginsToInstall(have, picks)
	if len(todo) == 0 {
		return &codexHomeReport{Home: h.home, State: pluginsCurrent}
	}
	if busy := r.homeBusy(h.home); busy != "" {
		return &codexHomeReport{Home: h.home, State: pluginsWaiting, Reason: busy}
	}
	installed, err := codex.InstallPlugins(ctx, binary, h.env, skills.MarketplaceDir(r.skills.dir), todo)
	if err != nil {
		return &codexHomeReport{Home: h.home, State: pluginsFailed, Reason: stream.Cut(err.Error(), maxPluginReason)}
	}
	r.log.Info("codex_plugins_installed", "home", h.home, "packages", strings.Join(installed, ","))

	return &codexHomeReport{Home: h.home, State: pluginsCurrent}
}

// logPluginState logs a state that is not current, once for each change.
func (r *router) logPluginState(rep codexHomeReport) {
	s := &r.skills
	s.mu.Lock()
	before := s.codexHomes[rep.Home]
	s.mu.Unlock()
	if rep.State == pluginsCurrent || before == rep {
		return
	}
	level := r.log.Warn
	if rep.State == pluginsWaiting {
		level = r.log.Info
	}
	level("codex_plugins_"+rep.State, "home", rep.Home, "reason", rep.Reason)
}

// setCodexHomes keeps the states of the last pass, by home.
func (s *skillState) setCodexHomes(reports []codexHomeReport) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.codexHomes = make(map[string]codexHomeReport, len(reports))
	for _, rep := range reports {
		s.codexHomes[rep.Home] = rep
	}
}

// codexHomeReports lists the states of the last pass in the order of the homes.
func (r *router) codexHomeReports() []codexHomeReport {
	s := &r.skills
	s.mu.Lock()
	defer s.mu.Unlock()
	reports := make([]codexHomeReport, 0, len(s.codexHomes))
	for _, rep := range s.codexHomes {
		reports = append(reports, rep)
	}
	slices.SortFunc(reports, func(a, b codexHomeReport) int { return strings.Compare(a.Home, b.Home) })

	return reports
}

// moveLegacySkills moves the old skill folders out of the user home, after the
// plugins are in every Codex home.
func (r *router) moveLegacySkills() {
	dest, err := codex.BackupLegacySkills(filepath.Join(skills.StoreDir(r.skills.dir), "backup"), time.Now())
	if err != nil {
		r.log.Warn("codex_legacy_skills_not_moved", "error", err.Error())

		return
	}
	if dest != "" {
		r.log.Info("codex_legacy_skills_moved", "to", dest)
	}
}

// homeBusy says why a worker uses the Codex home, and is "" when none does.
// Three things count: a live run on the home, a run that is about to start on it,
// and an interactive session that the bridge launched on it. The session counts
// for launchGrace after the launch, because a launcher can return before the
// terminal starts the program, and then while a process names its id. A pass
// that cannot list the processes counts an older session as busy.
func (r *router) homeBusy(home string) string {
	reason := "waiting for a worker on " + home + " to end"
	s := &r.skills

	r.mu.Lock()
	for _, run := range r.live {
		if run.p.spec.harnessName == rules.HarnessCodex && resolveCodexHome(run.p.spec.configDir) == home {
			r.mu.Unlock()

			return reason
		}
	}
	r.mu.Unlock()

	s.mu.Lock()
	for _, held := range s.homeHolds {
		if held == home {
			s.mu.Unlock()

			return reason
		}
	}
	s.mu.Unlock()

	var processes string
	listed := false
	now := time.Now()
	for session, sh := range skills.Homes(s.dir) {
		if filepath.Clean(sh.Home) != home {
			continue
		}
		if now.Sub(sh.At) < launchGrace {
			return reason
		}
		if !listed {
			list := s.processes
			if list == nil {
				list = listProcesses
			}
			out, err := list(context.Background())
			if err != nil {
				r.log.Warn("codex_plugins_processes_unlisted", "error", err.Error())

				return reason
			}
			processes, listed = out, true
		}
		if strings.Contains(processes, session) {
			return reason
		}
	}

	return ""
}

// recordSessionHome keeps the Codex home of an interactive session, so the
// bridge does not install on it while the session runs.
func (r *router) recordSessionHome(spec workerSpec) {
	if spec.harnessName != rules.HarnessCodex || r.skills.dir == "" {
		return
	}
	if err := skills.WriteHome(r.skills.dir, spec.sessionID, resolveCodexHome(spec.configDir)); err != nil {
		r.log.Warn("codex_session_home_not_recorded", "session_id", spec.sessionID, "error", err.Error())
	}
}

// giveCodexSkills hides the skills of the packages the project does not use, and
// holds the Codex home of the run until its worker ends. A store that cannot
// serve the project's list hides nothing, and the worker then sees every skill.
func (r *router) giveCodexSkills(spec *workerSpec, p pending) {
	s := &r.skills
	if s.dir == "" {
		return
	}
	s.mu.Lock()
	if s.homeHolds == nil {
		s.homeHolds = map[string]string{}
	}
	s.homeHolds[p.runID] = resolveCodexHome(spec.configDir)
	s.mu.Unlock()

	held := skills.Held(s.dir)
	if len(held) == 0 {
		return
	}
	spec.hideSkills = skills.HiddenSkills(s.dir, s.cliVersion, r.projectPackages(p.project, held))
}

// codexPackages maps each project slug to the packages Codex needs for it: the
// packages of the project that the store can serve to Codex. A project the app
// has not listed gets every package. A store with none gives an empty map.
func codexPackages(dir string, slugs []string, sites []api.Site) map[string][]string {
	if dir == "" {
		return nil
	}
	var held []string
	for _, p := range skills.CodexPicks(dir, version) {
		held = append(held, p.Package)
	}
	if len(held) == 0 {
		return nil
	}
	out := make(map[string][]string, len(slugs))
	for _, slug := range slugs {
		site := api.Site{}
		for _, candidate := range sites {
			if strings.EqualFold(candidate.ID, slug) || (candidate.Slug != "" && strings.EqualFold(candidate.Slug, slug)) {
				site = candidate

				break
			}
		}
		out[slug] = slices.DeleteFunc(site.PackagesIn(held), func(name string) bool { return !slices.Contains(held, name) })
	}

	return out
}

// checkInputs is what an account check reads about the Loupe plugins.
func checkInputs(slugs []string, sites []api.Site) (map[string][]string, bool) {
	dir, err := config.Dir()
	if err != nil {
		return nil, false
	}

	return codexPackages(dir, slugs, sites), len(codex.LegacySkillFolders()) > 0
}

// checkAccounts checks the accounts of the router's rule set with the projects
// the app listed last.
func (r *router) checkAccounts(ctx context.Context, set *rules.Set) []accountResult {
	r.skills.mu.Lock()
	sites := slices.Clone(r.skills.sites)
	r.skills.mu.Unlock()

	return checkAccountsWith(ctx, set, sites)
}

// describePluginReport is one line of `loupe status` for a home.
func describePluginReport(rep codexHomeReport) string {
	line := fmt.Sprintf("plugins in %s: %s", rep.Home, rep.State)
	if rep.Reason != "" {
		line += ": " + rep.Reason
	}

	return line
}
