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
	skillNames := skills.PickSkills(picks)
	legacy := len(codex.LegacySkillFolders(skillNames)) > 0

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
		r.moveLegacySkills(homes, skillNames)
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
	lock, busy, err := r.reserveHome(h.home)
	if err != nil {
		return &codexHomeReport{Home: h.home, State: pluginsFailed, Reason: stream.Cut(err.Error(), maxPluginReason)}
	}
	if busy != "" {
		return &codexHomeReport{Home: h.home, State: pluginsWaiting, Reason: busy}
	}
	defer lock.Release()
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
// plugins are in every Codex home. A running worker can read those folders, so
// the move waits for a pass with no worker on any home.
func (r *router) moveLegacySkills(homes []pluginHome, names []string) {
	for _, h := range homes {
		lock, busy, err := r.reserveHome(h.home)
		if err != nil {
			r.log.Warn("codex_legacy_skills_not_moved", "error", err.Error())

			return
		}
		if busy != "" {
			r.log.Info("codex_legacy_skills_waiting", "reason", busy)

			return
		}
		defer lock.Release()
	}
	dest, err := codex.BackupLegacySkills(filepath.Join(skills.StoreDir(r.skills.dir), "backup"), names, time.Now())
	if err != nil {
		r.log.Warn("codex_legacy_skills_not_moved", "error", err.Error())

		return
	}
	if dest != "" {
		r.log.Info("codex_legacy_skills_moved", "to", dest)
	}
}

// reserveHome takes the Codex home for an install. It returns the lock, or the
// reason the home is busy. The lock makes a worker start wait, so no worker starts
// between the check and the install.
func (r *router) reserveHome(home string) (*skills.HomeLock, string, error) {
	reason := "waiting for a worker on " + home + " to end"
	lock, ok, err := skills.ReserveHome(r.skills.dir, home)
	if err != nil {
		return nil, "", fmt.Errorf("reserve the Codex home: %w", err)
	}
	if !ok {
		return nil, reason, nil
	}
	if r.homeBusy(home) {
		lock.Release()

		return nil, reason, nil
	}

	return lock, "", nil
}

// homeBusy says whether a run or a session uses the Codex home with no lock on
// it: a live run that a former image started, or an interactive session that the
// bridge launched.
func (r *router) homeBusy(home string) bool {
	s := &r.skills

	r.mu.Lock()
	for _, run := range r.live {
		if run.p.spec.harnessName == rules.HarnessCodex && resolveCodexHome(run.p.spec.configDir) == home {
			r.mu.Unlock()

			return true
		}
	}
	r.mu.Unlock()

	list := s.processes
	if list == nil {
		list = listProcesses
	}
	used, err := sessionOnHome(s.dir, home, list)
	if err != nil {
		r.log.Warn("codex_plugins_processes_unlisted", "error", err.Error())
	}

	return used
}

// sessionOnHome says whether an interactive session that a bridge launched uses
// the Codex home. The session counts for launchGrace after the launch, because a
// launcher can return before the terminal starts the program, and then while a
// process names its id. A failure to list the processes counts an older session
// as busy, and it comes back with the error.
func sessionOnHome(dir, home string, list func(context.Context) (string, error)) (bool, error) {
	var processes string
	listed := false
	now := time.Now()
	for session, sh := range skills.Homes(dir) {
		if filepath.Clean(sh.Home) != filepath.Clean(home) {
			continue
		}
		if now.Sub(sh.At) < launchGrace {
			return true, nil
		}
		if !listed {
			out, err := list(context.Background())
			if err != nil {
				return true, err
			}
			processes, listed = out, true
		}
		if strings.Contains(processes, session) {
			return true, nil
		}
	}

	return false, nil
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
// holds the Codex home of the run until its worker ends. It waits while an install
// holds the home. It fails when the project names a package the store cannot serve.
func (r *router) giveCodexSkills(spec *workerSpec, p pending) error {
	s := &r.skills
	if s.dir == "" {
		return nil
	}
	servable := codexPackageNames(s.dir, s.cliVersion)
	needed, missing := splitPackages(r.projectPackages(p.project, servable), servable, s.cliVersion)
	if len(missing) > 0 {
		return fmt.Errorf("project %s needs package %s, and the skill store holds no Codex version of it; run `loupe update`", p.project, strings.Join(missing, ", "))
	}
	lock, err := skills.HoldHome(r.workerContext(), s.dir, resolveCodexHome(spec.configDir))
	if err != nil {
		return fmt.Errorf("hold the Codex home: %w", err)
	}
	s.mu.Lock()
	if s.homeHolds == nil {
		s.homeHolds = map[string]*skills.HomeLock{}
	}
	s.homeHolds[p.runID] = lock
	s.mu.Unlock()

	spec.hideSkills = skills.HiddenSkills(s.dir, s.cliVersion, needed)

	return nil
}

// codexPackageNames lists the packages the store can serve to Codex.
func codexPackageNames(dir, cliVersion string) []string {
	var names []string
	for _, p := range skills.CodexPicks(dir, cliVersion) {
		names = append(names, p.Package)
	}

	return names
}

// splitPackages splits the packages a project names into the ones the store can
// serve and the ones it cannot. A development build with an empty store has no
// starting copy, so it misses nothing, and the account's own plugins serve it.
func splitPackages(wanted, servable []string, cliVersion string) (needed, missing []string) {
	for _, name := range wanted {
		switch {
		case slices.Contains(servable, name):
			needed = append(needed, name)
		case len(servable) > 0 || cliVersion != "":
			missing = append(missing, name)
		}
	}

	return needed, missing
}

// codexPackages maps each project slug to the packages Codex needs for it that
// the store can serve, and to the ones it cannot. A project the app has not listed
// gets every package the store can serve. No store gives two nil maps.
func codexPackages(dir string, slugs []string, sites []api.Site) (needed, missing map[string][]string) {
	if dir == "" {
		return nil, nil
	}
	servable := codexPackageNames(dir, version)
	needed, missing = make(map[string][]string, len(slugs)), map[string][]string{}
	for _, slug := range slugs {
		site := api.Site{}
		for _, candidate := range sites {
			if strings.EqualFold(candidate.ID, slug) || (candidate.Slug != "" && strings.EqualFold(candidate.Slug, slug)) {
				site = candidate

				break
			}
		}
		have, lacks := splitPackages(site.PackagesIn(servable), servable, version)
		needed[slug] = have
		if len(lacks) > 0 {
			missing[slug] = lacks
		}
	}

	return needed, missing
}

// codexCheckInputs is what an account check reads about the Loupe plugins.
type codexCheckInputs struct {
	packages map[string][]string
	missing  map[string][]string
	legacy   bool
}

// checkInputs reads the store and the user home for an account check.
func checkInputs(slugs []string, sites []api.Site) codexCheckInputs {
	dir, err := config.Dir()
	if err != nil {
		return codexCheckInputs{}
	}
	in := codexCheckInputs{}
	in.packages, in.missing = codexPackages(dir, slugs, sites)
	in.legacy = len(codex.LegacySkillFolders(skills.PickSkills(skills.CodexPicks(dir, version)))) > 0

	return in
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
