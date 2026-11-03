package cmd

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/loupe-ac/loupe/cli/internal/skills"
)

// fakeCodexBin is a codex on PATH. It logs each call with CODEX_HOME, prints the
// plugin list from a file the test writes, and installs nothing.
type fakeCodexBin struct {
	path, log, plugins string
}

func newFakeCodexBin(t *testing.T, plugins string) fakeCodexBin {
	t.Helper()
	bin, state := t.TempDir(), t.TempDir()
	f := fakeCodexBin{path: filepath.Join(bin, "codex"), log: filepath.Join(state, "calls"), plugins: filepath.Join(state, "plugins.json")}
	f.setPlugins(t, plugins)
	script := `#!/bin/sh
echo "$* | $CODEX_HOME" >> '` + f.log + `'
case "$*" in
"plugin list --json") if [ -f '` + f.plugins + `'."$(basename "$CODEX_HOME")" ]; then cat '` + f.plugins + `'."$(basename "$CODEX_HOME")"; else cat '` + f.plugins + `'; fi; exit 0 ;;
"plugin marketplace list --json") echo '{"marketplaces":[]}'; exit 0 ;;
"plugin marketplace add "*) exit 0 ;;
"plugin add fail@"*) echo "Error: no such plugin" >&2; exit 1 ;;
"plugin add "*) exit 0 ;;
esac
exit 2
`
	if err := os.WriteFile(f.path, []byte(script), 0o700); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin+string(os.PathListSeparator)+os.Getenv("PATH"))

	return f
}

func (f fakeCodexBin) setPlugins(t *testing.T, body string) {
	t.Helper()
	if err := os.WriteFile(f.plugins, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
}

// setPluginsFor sets the plugin list of one home.
func (f fakeCodexBin) setPluginsFor(t *testing.T, home, body string) {
	t.Helper()
	if err := os.WriteFile(f.plugins+"."+filepath.Base(home), []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
}

// adds lists the plugin add calls, as <package>@<home>.
func (f fakeCodexBin) adds(t *testing.T) []string {
	t.Helper()
	b, _ := os.ReadFile(f.log)
	var out []string
	for _, line := range strings.Split(string(b), "\n") {
		if rest, ok := strings.CutPrefix(line, "plugin add "); ok {
			name, home, _ := strings.Cut(rest, "@loupe-local | ")
			out = append(out, name+"@"+home)
		}
	}

	return out
}

func installedList(entries ...string) string {
	return `{"installed":[` + strings.Join(entries, ",") + `],"available":[]}`
}

func installedPlugin(name, version string) string {
	return fmt.Sprintf(`{"name":%q,"marketplaceName":"loupe-local","version":%q,"installed":true,"enabled":true}`, name, version)
}

// putPackage puts a package version with a Codex manifest in the store.
func putPackage(t *testing.T, store, pkg, version string, skillNames ...string) {
	t.Helper()
	dir := filepath.Join(skills.StoreDir(store), pkg, version)
	writeFile(t, filepath.Join(dir, ".codex-plugin", "plugin.json"), `{"name":"`+pkg+`","version":"`+version+`"}`)
	for _, name := range skillNames {
		writeFile(t, filepath.Join(dir, "skills", name, "SKILL.md"), "# "+name+"\n")
	}
}

// codexSet is a rule set with one Codex account for each home.
func codexSet(t *testing.T, homes ...string) *rules.Set {
	t.Helper()
	var b strings.Builder
	if len(homes) > 0 {
		b.WriteString("accounts:\n")
	}
	for i, h := range homes {
		fmt.Fprintf(&b, "  cx%d:\n    harness: codex\n    codexHome: %s\n", i, h)
	}
	if len(homes) > 0 {
		b.WriteString("defaults:\n  account: cx0\n")
	}
	fmt.Fprintf(&b, "projects:\n  loupe:\n    dir: %s\nwork:\n  plan:\n    prompt: go\n", t.TempDir())
	set, err := rules.Parse([]byte(b.String()), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}

	return set
}

// syncWorld is a router with a store, two packages, a user home with the old
// skill folders and a fake codex.
type syncWorld struct {
	r      *router
	log    *bytes.Buffer
	store  string
	user   string
	codex  fakeCodexBin
	legacy []string
}

func newSyncWorld(t *testing.T, legacy bool, plugins string, homes ...string) *syncWorld {
	t.Helper()
	w := &syncWorld{store: t.TempDir(), user: t.TempDir()}
	t.Setenv("HOME", w.user)
	t.Setenv("CODEX_HOME", "")
	putPackage(t, w.store, "loupe", "1.0.0", "loupe-board", "loupe-inbox")
	putPackage(t, w.store, "loupe-lifecycle", "0.3.0", "loupe-stage-merge")
	if legacy {
		for _, name := range []string{"loupe-board", "loupe-stage-merge"} {
			writeFile(t, filepath.Join(w.user, ".agents", "skills", name, "SKILL.md"), "# old\n")
			w.legacy = append(w.legacy, filepath.Join(w.user, ".agents", "skills", name))
		}
		writeFile(t, filepath.Join(w.user, ".agents", "skills", "mine", "SKILL.md"), "# mine\n")
	}
	w.codex = newFakeCodexBin(t, plugins)
	w.r, w.log = skillRouter(nil)
	w.r.skills.dir = w.store
	w.r.set.Store(codexSet(t, homes...))

	return w
}

func (w *syncWorld) legacyLeft() int {
	n := 0
	for _, p := range w.legacy {
		if _, err := os.Stat(p); err == nil {
			n++
		}
	}

	return n
}

func (w *syncWorld) backupOf(name string) string {
	matches, _ := filepath.Glob(filepath.Join(skills.StoreDir(w.store), "backup", "*", name, "SKILL.md"))
	if len(matches) == 0 {
		return ""
	}
	b, _ := os.ReadFile(matches[0])

	return string(b)
}

func TestSyncBuildsTheMarketplaceAndWritesNothingToAHomeWithNoPluginsAndNoOldFolders(t *testing.T) {
	home := t.TempDir()
	w := newSyncWorld(t, false, installedList(), home)

	w.r.syncCodexPlugins(context.Background())

	if adds := w.codex.adds(t); len(adds) != 0 {
		t.Fatalf("adds = %v", adds)
	}
	if b, _ := os.ReadFile(w.codex.log); strings.Contains(string(b), "marketplace") {
		t.Fatalf("the bridge touched the marketplace of a home it should leave alone:\n%s", b)
	}
	if got := w.r.codexHomeReports(); len(got) != 0 {
		t.Fatalf("reports = %+v", got)
	}
	if _, err := os.Stat(filepath.Join(skills.MarketplaceDir(w.store), ".agents", "plugins", "marketplace.json")); err != nil {
		t.Fatalf("no marketplace: %v", err)
	}
}

func TestSyncInstallsInEveryHomeThenMovesTheOldSkillFolders(t *testing.T) {
	a, b := t.TempDir(), t.TempDir()
	w := newSyncWorld(t, true, installedList(), a, b)

	w.r.syncCodexPlugins(context.Background())

	want := []string{"loupe@" + a, "loupe-lifecycle@" + a, "loupe@" + b, "loupe-lifecycle@" + b}
	if adds := w.codex.adds(t); !slices.Equal(adds, want) {
		t.Fatalf("adds = %v, want %v", adds, want)
	}
	for _, rep := range w.r.codexHomeReports() {
		if rep.State != pluginsCurrent {
			t.Fatalf("report = %+v", rep)
		}
	}
	if w.legacyLeft() != 0 || w.backupOf("loupe-board") != "# old\n" || w.backupOf("loupe-stage-merge") != "# old\n" {
		t.Fatalf("old folders left %d; backup %q", w.legacyLeft(), w.backupOf("loupe-board"))
	}
	if _, err := os.Stat(filepath.Join(w.user, ".agents", "skills", "mine", "SKILL.md")); err != nil {
		t.Fatalf("a folder of the user moved: %v", err)
	}
	if !strings.Contains(w.log.String(), "codex_legacy_skills_moved") {
		t.Fatalf("log:\n%s", w.log)
	}
}

func TestSyncWaitsForAWorkerOnTheHomeAndMovesNothingUntilTheLastHomeIsDone(t *testing.T) {
	busy, free := t.TempDir(), t.TempDir()
	w := newSyncWorld(t, true, installedList(), busy, free)
	w.r.live = map[string]liveRun{"run-1": {p: pending{runID: "run-1", spec: workerSpec{harnessName: rules.HarnessCodex, configDir: busy}}}}

	w.r.syncCodexPlugins(context.Background())

	if adds := w.codex.adds(t); !slices.Equal(adds, []string{"loupe@" + free, "loupe-lifecycle@" + free}) {
		t.Fatalf("adds = %v", adds)
	}
	states := map[string]codexHomeReport{}
	for _, rep := range w.r.codexHomeReports() {
		states[rep.Home] = rep
	}
	if got := states[busy]; got.State != pluginsWaiting || got.Reason != "waiting for a worker on "+busy+" to end" {
		t.Fatalf("busy report = %+v", got)
	}
	if states[free].State != pluginsCurrent {
		t.Fatalf("free report = %+v", states[free])
	}
	if w.legacyLeft() != 2 {
		t.Fatal("the old folders moved before the last home was done")
	}
	if !strings.Contains(w.log.String(), "codex_plugins_waiting") {
		t.Fatalf("log:\n%s", w.log)
	}

	w.codex.setPluginsFor(t, free, installedList(installedPlugin("loupe", "1.0.0"), installedPlugin("loupe-lifecycle", "0.3.0")))
	delete(w.r.live, "run-1")
	w.r.syncCodexPlugins(context.Background())

	if adds := w.codex.adds(t); len(adds) != 4 {
		t.Fatalf("adds = %v", adds)
	}
	if w.legacyLeft() != 0 {
		t.Fatal("the old folders stay after every home is done")
	}
	for _, rep := range w.r.codexHomeReports() {
		if rep.State != pluginsCurrent {
			t.Fatalf("report = %+v", rep)
		}
	}
}

func TestSyncUpdatesAHomeThatHoldsAnOlderVersionAndKeepsACurrentOne(t *testing.T) {
	home := t.TempDir()
	w := newSyncWorld(t, false, installedList(installedPlugin("loupe", "1.0.0"), installedPlugin("loupe-lifecycle", "0.2.0")), home)

	w.r.syncCodexPlugins(context.Background())

	if adds := w.codex.adds(t); !slices.Equal(adds, []string{"loupe-lifecycle@" + home}) {
		t.Fatalf("adds = %v", adds)
	}
}

func TestSyncWaitsForAnInteractiveSessionOnTheHome(t *testing.T) {
	home := t.TempDir()
	w := newSyncWorld(t, false, installedList(installedPlugin("loupe", "0.9.0")), home)
	if err := skills.WriteHome(w.store, "sess-1", home); err != nil {
		t.Fatal(err)
	}
	processes := "123 codex -c mcp_servers.loupe.env.LOUPE_SESSION_ID=\"sess-1\"\n"
	w.r.skills.processes = func(context.Context) (string, error) { return processes, nil }
	old := time.Now().Add(-2 * launchGrace)
	if err := os.Chtimes(filepath.Join(skills.StoreDir(w.store), ".homes", "sess-1"), old, old); err != nil {
		t.Fatal(err)
	}

	w.r.syncCodexPlugins(context.Background())
	if got := w.r.codexHomeReports(); len(got) != 1 || got[0].State != pluginsWaiting {
		t.Fatalf("a running session did not hold the home: %+v", got)
	}

	processes = "123 vim\n"
	w.r.syncCodexPlugins(context.Background())
	if got := w.r.codexHomeReports(); len(got) != 1 || got[0].State != pluginsCurrent {
		t.Fatalf("an ended session still holds the home: %+v", got)
	}
	if adds := w.codex.adds(t); len(adds) != 2 {
		t.Fatalf("adds = %v", adds)
	}
}

func TestSyncCountsAFreshLaunchAsBusyWhenTheProcessIsNotThereYet(t *testing.T) {
	home := t.TempDir()
	w := newSyncWorld(t, false, installedList(installedPlugin("loupe", "0.9.0")), home)
	w.r.skills.processes = func(context.Context) (string, error) { return "1 init\n", nil }
	w.r.recordSessionHome(workerSpec{harnessName: rules.HarnessCodex, configDir: home, sessionID: "sess-2"})

	w.r.syncCodexPlugins(context.Background())

	if got := w.r.codexHomeReports(); len(got) != 1 || got[0].State != pluginsWaiting {
		t.Fatalf("reports = %+v", got)
	}
}

func TestSyncReportsAFailedInstallAndKeepsTheOldFolders(t *testing.T) {
	home := t.TempDir()
	w := newSyncWorld(t, true, installedList(), home)
	putPackage(t, w.store, "fail", "1.0.0")

	w.r.syncCodexPlugins(context.Background())

	got := w.r.codexHomeReports()
	if len(got) != 1 || got[0].State != pluginsFailed || !strings.Contains(got[0].Reason, "install plugin fail") {
		t.Fatalf("reports = %+v", got)
	}
	if w.legacyLeft() != 2 {
		t.Fatal("the old folders moved after a failed install")
	}
}

func TestSyncDoesNothingForARuleFileWithNoCodexAccount(t *testing.T) {
	w := newSyncWorld(t, true, installedList())
	set, err := rules.Parse([]byte("projects:\n  loupe:\n    dir: "+t.TempDir()+"\nwork:\n  plan:\n    prompt: go\n"), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	w.r.set.Store(set)

	w.r.syncCodexPlugins(context.Background())

	if w.legacyLeft() != 2 {
		t.Fatal("the old folders moved with no Codex account")
	}
	if _, err := os.Stat(w.codex.log); err == nil {
		t.Fatal("codex ran")
	}
}

func TestGivePackagesHidesTheSkillsOfPackagesTheProjectDoesNotUse(t *testing.T) {
	store := t.TempDir()
	putPackage(t, store, "loupe", "1.0.0", "loupe-board")
	putPackage(t, store, "loupe-lifecycle", "0.3.0", "loupe-stage-merge", "loupe-profile")
	r, _ := skillRouter(func(context.Context) ([]api.Site, error) {
		return []api.Site{{ID: "p1", Slug: "plain", SkillPackages: []string{"loupe"}}, {ID: "p2", Slug: "life", SkillPackages: []string{"loupe", "loupe-lifecycle"}}}, nil
	})
	r.loadSkills(context.Background())
	r.skills.dir = store
	home := t.TempDir()

	spec := workerSpec{harnessName: rules.HarnessCodex, configDir: home}
	if err := r.givePackages(&spec, pending{project: "plain", runID: "r1"}); err != nil {
		t.Fatal(err)
	}
	want := []string{"loupe-lifecycle:loupe-stage-merge", "loupe-lifecycle:loupe-profile"}
	slices.Sort(want)
	got := slices.Clone(spec.hideSkills)
	slices.Sort(got)
	if !slices.Equal(got, want) {
		t.Fatalf("hidden = %v, want %v", got, want)
	}
	if !slices.Equal(spec.harnessSpec(nil).HideSkills, spec.hideSkills) {
		t.Fatal("the harness spec lacks the hidden skills")
	}
	if r.skills.homeHolds["r1"] != home {
		t.Fatalf("homeHolds = %v", r.skills.homeHolds)
	}
	r.releasePackages("r1")
	if len(r.skills.homeHolds) != 0 {
		t.Fatalf("homeHolds = %v", r.skills.homeHolds)
	}

	life := workerSpec{harnessName: rules.HarnessCodex, configDir: home}
	if err := r.givePackages(&life, pending{project: "life", runID: "r2"}); err != nil || len(life.hideSkills) != 0 {
		t.Fatalf("hidden = %v, %v", life.hideSkills, err)
	}
	if life.packages != nil || life.mcpConfig != "" {
		t.Fatal("a Codex worker got Claude plugin folders")
	}
}

func TestAHeldCodexRunKeepsTheHomeBusy(t *testing.T) {
	home := t.TempDir()
	w := newSyncWorld(t, false, installedList(installedPlugin("loupe", "0.9.0")), home)
	spec := workerSpec{harnessName: rules.HarnessCodex, configDir: home}
	if err := w.r.givePackages(&spec, pending{project: "x", runID: "r1"}); err != nil {
		t.Fatal(err)
	}

	w.r.syncCodexPlugins(context.Background())
	if got := w.r.codexHomeReports(); len(got) != 1 || got[0].State != pluginsWaiting {
		t.Fatalf("reports = %+v", got)
	}
	w.r.releasePackages("r1")
	w.r.syncCodexPlugins(context.Background())
	if got := w.r.codexHomeReports(); len(got) != 1 || got[0].State != pluginsCurrent {
		t.Fatalf("reports = %+v", got)
	}
}

func TestCodexPackagesNameTheServableOnesOfEachProject(t *testing.T) {
	store := t.TempDir()
	putPackage(t, store, "loupe", "1.0.0")
	putPackage(t, store, "loupe-lifecycle", "0.3.0")
	sites := []api.Site{{ID: "p1", Slug: "plain", SkillPackages: []string{"loupe", "elsewhere"}}}

	got := codexPackages(store, []string{"plain", "unlisted"}, sites)

	if !slices.Equal(got["plain"], []string{"loupe"}) || !slices.Equal(got["unlisted"], []string{"loupe", "loupe-lifecycle"}) {
		t.Fatalf("packages = %v", got)
	}
	if got := codexPackages(t.TempDir(), []string{"plain"}, sites); got != nil {
		t.Fatalf("an empty store gave %v", got)
	}
}

func TestStatusShowsTheStateOfEachCodexHome(t *testing.T) {
	var out bytes.Buffer
	bridges := []bridgeReport{{status: statusResult{Name: "main", CodexPlugins: []codexHomeReport{
		{Home: "/h/a", State: pluginsCurrent},
		{Home: "/h/b", State: pluginsWaiting, Reason: "waiting for a worker on /h/b to end"},
	}}}}

	noteCodexPlugins(&out, bridges)

	want := "Codex:       plugins in /h/a: current (bridge main)\n" +
		"Codex:       plugins in /h/b: waiting: waiting for a worker on /h/b to end (bridge main)\n"
	if out.String() != want {
		t.Fatalf("out = %q, want %q", out.String(), want)
	}
}

// initPluginWorld is an init run on a machine with Codex alone, a store with two
// packages, and the old Loupe skill folders in the user home.
func initPluginWorld(t *testing.T, plugins string) (*initWorld, fakeCodexBin, []string) {
	t.Helper()
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execOut["codex mcp get loupe --json"] = codexDeclaration(true, "loupe")
	inRepo(t, w.server.URL)
	dir, err := config.Dir()
	if err != nil {
		t.Fatal(err)
	}
	putPackage(t, dir, "loupe", "1.0.0", "loupe-board")
	putPackage(t, dir, "loupe-lifecycle", "0.3.0", "loupe-stage-merge")
	fake := newFakeCodexBin(t, plugins)
	look := initLookPath
	initLookPath = func(name string) (string, error) {
		if name == "codex" {
			return fake.path, nil
		}

		return look(name)
	}
	user, _ := os.UserHomeDir()
	var legacy []string
	for _, name := range []string{"loupe-board", "loupe-stage-merge"} {
		legacy = append(legacy, filepath.Join(user, ".agents", "skills", name))
		writeFile(t, filepath.Join(legacy[len(legacy)-1], "SKILL.md"), "# old\n")
	}
	old := initBusyHomes
	t.Cleanup(func() { initBusyHomes = old })
	initBusyHomes = func() map[string]bool { return nil }

	return w, fake, legacy
}

func anyExists(paths []string) bool {
	for _, p := range paths {
		if _, err := os.Stat(p); err == nil {
			return true
		}
	}

	return false
}

func TestInitInstallsTheCodexPluginsAndMovesTheOldSkillFolders(t *testing.T) {
	w, fake, legacy := initPluginWorld(t, installedList())

	out, err := runInitCmd(t, "1\nn\n")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	if adds := fake.adds(t); !slices.Equal(adds, []string{"loupe@" + w.codexHome, "loupe-lifecycle@" + w.codexHome}) {
		t.Fatalf("adds = %v", adds)
	}
	for _, want := range []string{
		"Codex plugins: done, installed loupe, loupe-lifecycle in " + w.codexHome,
		"Codex skills: done, moved the old Loupe skill folders of ~/.agents/skills to ",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if anyExists(legacy) {
		t.Error("the old skill folders stay")
	}
	dir, _ := config.Dir()
	matches, _ := filepath.Glob(filepath.Join(skills.StoreDir(dir), "backup", "*", "loupe-board", "SKILL.md"))
	if len(matches) != 1 {
		t.Errorf("backup = %v", matches)
	}
}

func TestInitKeepsCurrentCodexPluginsAndStillMovesTheOldFolders(t *testing.T) {
	w, fake, legacy := initPluginWorld(t, installedList(installedPlugin("loupe", "1.0.0"), installedPlugin("loupe-lifecycle", "0.3.0")))

	out, err := runInitCmd(t, "1\nn\n")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	if adds := fake.adds(t); len(adds) != 0 {
		t.Fatalf("adds = %v", adds)
	}
	if !strings.Contains(out, "Codex plugins: skipped, the Loupe plugins in "+w.codexHome+" are current") || anyExists(legacy) {
		t.Errorf("legacy left: %v\n%s", anyExists(legacy), out)
	}
}

func TestInitLeavesABusyCodexHomeToTheBridgeAndKeepsTheOldFolders(t *testing.T) {
	w, fake, legacy := initPluginWorld(t, installedList())
	initBusyHomes = func() map[string]bool { return map[string]bool{w.codexHome: true} }

	out, err := runInitCmd(t, "1\nn\n")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	if !strings.Contains(out, "Codex plugins: skipped, a worker runs on "+w.codexHome+", the bridge installs it later") {
		t.Errorf("output:\n%s", out)
	}
	if adds := fake.adds(t); len(adds) != 0 || !anyExists(legacy) {
		t.Errorf("adds = %v, legacy left = %v", adds, anyExists(legacy))
	}
}

func TestInitReportsAFailedCodexPluginInstallAndKeepsTheOldFolders(t *testing.T) {
	_, _, legacy := initPluginWorld(t, installedList())
	dir, _ := config.Dir()
	putPackage(t, dir, "fail", "1.0.0")

	out, err := runInitCmd(t, "1\nn\n")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	if !strings.Contains(out, "Codex plugins: failed") || !strings.Contains(out, "install plugin fail") || !anyExists(legacy) {
		t.Errorf("legacy left = %v\n%s", anyExists(legacy), out)
	}
}
