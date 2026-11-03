package codex

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/skills"
)

// pluginFixture is a fake codex that logs each call and keeps the plugin list
// and the marketplace list in two files the test writes.
type pluginFixture struct {
	env     []string
	project string
	log     string
	plugins string
	markets string
}

func fakeCodexPlugins(t *testing.T, plugins, markets string) pluginFixture {
	t.Helper()
	bin, project, state := t.TempDir(), t.TempDir(), t.TempDir()
	f := pluginFixture{project: project, log: filepath.Join(state, "calls"), plugins: filepath.Join(state, "plugins.json"), markets: filepath.Join(state, "markets.json")}
	write := func(path, body string) {
		if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	write(f.plugins, plugins)
	write(f.markets, markets)
	script := `#!/bin/sh
echo "$* | $CODEX_HOME" >> '` + f.log + `'
case "$*" in
"login status") exit 0 ;;
"plugin list --json") cat '` + f.plugins + `'; exit 0 ;;
"plugin marketplace list --json") cat '` + f.markets + `'; exit 0 ;;
"plugin marketplace add "*) exit 0 ;;
"plugin marketplace remove "*) exit 0 ;;
"plugin add fail@"*) echo "Error: plugin was not found" >&2; exit 1 ;;
"plugin add "*) exit 0 ;;
"mcp get loupe --json") echo '{"enabled":true,"transport":{"type":"stdio","command":"loupe","args":["mcp"]}}'; exit 0 ;;
esac
exit 2
`
	write(filepath.Join(bin, "codex"), script)
	write(filepath.Join(bin, "loupe"), "#!/bin/sh\n")
	for _, name := range []string{"codex", "loupe"} {
		if err := os.Chmod(filepath.Join(bin, name), 0o700); err != nil {
			t.Fatal(err)
		}
	}
	f.env = []string{"PATH=" + bin + ":/bin:/usr/bin"}

	return f
}

func (f pluginFixture) calls(t *testing.T) []string {
	t.Helper()
	b, _ := os.ReadFile(f.log)

	return strings.Split(strings.TrimSpace(string(b)), "\n")
}

func installedJSON(entries ...string) string {
	return `{"installed":[` + strings.Join(entries, ",") + `],"available":[]}`
}

func pluginJSON(name, version string, enabled bool) string {
	e := "false"
	if enabled {
		e = "true"
	}

	return `{"pluginId":"` + name + `@loupe-local","name":"` + name + `","marketplaceName":"loupe-local","version":"` + version + `","installed":true,"enabled":` + e + `}`
}

func marketsJSON(root string) string {
	if root == "" {
		return `{"marketplaces":[]}`
	}

	return `{"marketplaces":[{"name":"loupe-local","root":"` + root + `"}]}`
}

func picks(pairs ...string) []skills.Pick {
	var out []skills.Pick
	for i := 0; i < len(pairs); i += 2 {
		out = append(out, skills.Pick{Package: pairs[i], Version: pairs[i+1]})
	}

	return out
}

func TestListPluginsKeepsOnlyTheLoupeMarketplace(t *testing.T) {
	other := `{"pluginId":"x@other","name":"x","marketplaceName":"other","version":"1.0.0","installed":true,"enabled":true}`
	f := fakeCodexPlugins(t, installedJSON(pluginJSON("loupe", "1.0.0", true), other), marketsJSON(""))

	got, err := ListPlugins(context.Background(), findCodex(f), HomeEnv(f.env, "/home/a"))
	if err != nil || len(got) != 1 || got["loupe"].Version != "1.0.0" || !got["loupe"].Enabled {
		t.Fatalf("plugins = %+v, %v", got, err)
	}
	if calls := f.calls(t); calls[0] != "plugin list --json | /home/a" {
		t.Fatalf("calls = %q", calls)
	}
}

// findCodex is the path of the fake codex in the fixture.
func findCodex(f pluginFixture) string {
	return filepath.Join(strings.SplitN(strings.TrimPrefix(f.env[0], "PATH="), ":", 2)[0], "codex")
}

func TestSyncPluginsInstallsOnlyWhatDiffers(t *testing.T) {
	root := t.TempDir()
	f := fakeCodexPlugins(t, installedJSON(pluginJSON("loupe", "1.0.0", true), pluginJSON("loupe-lifecycle", "0.2.0", true)), marketsJSON(""))

	got, err := SyncPlugins(context.Background(), findCodex(f), HomeEnv(f.env, "/home/a"), root, picks("loupe", "1.0.0", "loupe-lifecycle", "0.3.0"))
	if err != nil || !slices.Equal(got, []string{"loupe-lifecycle"}) {
		t.Fatalf("installed = %v, %v", got, err)
	}
	want := []string{
		"plugin list --json | /home/a",
		"plugin marketplace list --json | /home/a",
		"plugin marketplace add " + root + " | /home/a",
		"plugin add loupe-lifecycle@loupe-local | /home/a",
	}
	if calls := f.calls(t); !slices.Equal(calls, want) {
		t.Fatalf("calls = %q, want %q", calls, want)
	}
}

func TestSyncPluginsWritesNothingWhenEveryVersionMatches(t *testing.T) {
	f := fakeCodexPlugins(t, installedJSON(pluginJSON("loupe", "1.0.0", true)), marketsJSON(""))

	got, err := SyncPlugins(context.Background(), findCodex(f), HomeEnv(f.env, "/home/a"), t.TempDir(), picks("loupe", "1.0.0"))
	if err != nil || got != nil {
		t.Fatalf("installed = %v, %v", got, err)
	}
	if calls := f.calls(t); len(calls) != 1 {
		t.Fatalf("calls = %q, want the list alone", calls)
	}
}

func TestSyncPluginsKeepsAMarketplaceAtTheSameFolder(t *testing.T) {
	root := t.TempDir()
	link := filepath.Join(t.TempDir(), "link")
	if err := os.Symlink(root, link); err != nil {
		t.Fatal(err)
	}
	f := fakeCodexPlugins(t, installedJSON(), marketsJSON(link))

	if _, err := SyncPlugins(context.Background(), findCodex(f), HomeEnv(f.env, "/h"), root, picks("loupe", "1.0.0")); err != nil {
		t.Fatal(err)
	}
	for _, call := range f.calls(t) {
		if strings.Contains(call, "marketplace add") || strings.Contains(call, "marketplace remove") {
			t.Fatalf("calls = %q", f.calls(t))
		}
	}
}

func TestSyncPluginsReplacesAMarketplaceOfAnotherFolder(t *testing.T) {
	root := t.TempDir()
	f := fakeCodexPlugins(t, installedJSON(), marketsJSON("/elsewhere"))

	if _, err := SyncPlugins(context.Background(), findCodex(f), HomeEnv(f.env, "/h"), root, picks("loupe", "1.0.0")); err != nil {
		t.Fatal(err)
	}
	calls := strings.Join(f.calls(t), "\n")
	if remove, add := strings.Index(calls, "marketplace remove loupe-local"), strings.Index(calls, "marketplace add "+root); remove < 0 || add < remove {
		t.Fatalf("calls = %q", calls)
	}
}

func TestSyncPluginsNamesTheFailure(t *testing.T) {
	f := fakeCodexPlugins(t, installedJSON(), marketsJSON(""))

	got, err := SyncPlugins(context.Background(), findCodex(f), HomeEnv(f.env, "/h"), t.TempDir(), picks("loupe", "1.0.0", "fail", "1.0.0"))
	if err == nil || !strings.Contains(err.Error(), "install plugin fail") || !strings.Contains(err.Error(), "plugin was not found") {
		t.Fatalf("err = %v", err)
	}
	if !slices.Equal(got, []string{"loupe"}) {
		t.Fatalf("installed = %v", got)
	}
}

func TestSyncPluginsTimesOut(t *testing.T) {
	f := fakeCodexPlugins(t, installedJSON(), marketsJSON(""))
	script := "#!/bin/sh\nsleep 5\n"
	if err := os.WriteFile(findCodex(f), []byte(script), 0o700); err != nil {
		t.Fatal(err)
	}
	old := checkTimeout
	checkTimeout = 50 * time.Millisecond
	defer func() { checkTimeout = old }()

	if _, err := SyncPlugins(context.Background(), findCodex(f), HomeEnv(f.env, "/h"), t.TempDir(), picks("loupe", "1.0.0")); err == nil {
		t.Fatal("a hung codex gave no error")
	}
}

func TestCheckFailsAProjectThatNamesAPackageTheStoreCannotServe(t *testing.T) {
	home := t.TempDir()
	if err := os.WriteFile(filepath.Join(home, "config.toml"), []byte(approveCfg), 0o600); err != nil {
		t.Fatal(err)
	}
	f := fakeCodexPlugins(t, installedJSON(pluginJSON("loupe", "1.0.0", true)), marketsJSON(""))
	got := New(home, "", "").Check(context.Background(), harness.CheckSpec{
		ConfigDir: home, Env: f.env, Projects: map[string]string{"loupe": f.project},
		Packages:        map[string][]string{"loupe": {"loupe"}},
		MissingPackages: map[string][]string{"loupe": {"elsewhere"}},
		LegacySkills:    true,
	})
	if len(got) != 1 || got[0].Project != "loupe" || !strings.Contains(got[0].Reason, "elsewhere") || got[0].Detail != "run `loupe update`" {
		t.Fatalf("problems = %+v", got)
	}
}

func TestCheckRequiresTheInstalledPluginsOfTheProject(t *testing.T) {
	home := t.TempDir()
	if err := os.WriteFile(filepath.Join(home, "config.toml"), []byte(approveCfg), 0o600); err != nil {
		t.Fatal(err)
	}
	h := New(home, "", "")
	check := func(f pluginFixture, packages map[string][]string, legacy bool) []string {
		got := h.Check(context.Background(), harness.CheckSpec{
			ConfigDir: home, Env: f.env, Projects: map[string]string{"loupe": f.project}, Packages: packages, LegacySkills: legacy,
		})
		var out []string
		for _, p := range got {
			if p.Project != "loupe" {
				t.Fatalf("problem %+v names no project", p)
			}
			out = append(out, p.Reason+" | "+p.Detail)
		}

		return out
	}
	need := map[string][]string{"loupe": {"loupe", "loupe-lifecycle"}}

	t.Run("passes with every plugin installed and enabled", func(t *testing.T) {
		f := fakeCodexPlugins(t, installedJSON(pluginJSON("loupe", "1.0.0", true), pluginJSON("loupe-lifecycle", "0.3.0", true)), marketsJSON(""))
		if got := check(f, need, false); len(got) != 0 {
			t.Fatalf("problems = %q", got)
		}
		if calls := f.calls(t); !slices.Contains(calls, "plugin list --json | "+home) {
			t.Fatalf("calls = %q", calls)
		}
	})
	t.Run("names a missing plugin", func(t *testing.T) {
		f := fakeCodexPlugins(t, installedJSON(pluginJSON("loupe", "1.0.0", true)), marketsJSON(""))
		want := []string{"loupe plugin loupe-lifecycle is not installed | run `loupe init`"}
		if got := check(f, need, false); !slices.Equal(got, want) {
			t.Fatalf("problems = %q, want %q", got, want)
		}
	})
	t.Run("names a disabled plugin", func(t *testing.T) {
		f := fakeCodexPlugins(t, installedJSON(pluginJSON("loupe", "1.0.0", false), pluginJSON("loupe-lifecycle", "0.3.0", true)), marketsJSON(""))
		if got := check(f, need, false); len(got) != 1 || !strings.HasPrefix(got[0], "loupe plugin loupe is disabled") {
			t.Fatalf("problems = %q", got)
		}
	})
	t.Run("a plugin the project does not need is not required", func(t *testing.T) {
		f := fakeCodexPlugins(t, installedJSON(pluginJSON("loupe", "1.0.0", true)), marketsJSON(""))
		if got := check(f, map[string][]string{"loupe": {"loupe"}}, false); len(got) != 0 {
			t.Fatalf("problems = %q", got)
		}
	})
	t.Run("an unreadable list is a problem", func(t *testing.T) {
		f := fakeCodexPlugins(t, `not json`, marketsJSON(""))
		if got := check(f, need, false); len(got) != 1 || !strings.HasPrefix(got[0], "codex plugins could not be read") {
			t.Fatalf("problems = %q", got)
		}
	})
	t.Run("the old skill folders stand in for the plugins", func(t *testing.T) {
		f := fakeCodexPlugins(t, installedJSON(), marketsJSON(""))
		if got := check(f, need, true); len(got) != 0 {
			t.Fatalf("problems = %q", got)
		}
		if calls := f.calls(t); slices.Contains(calls, "plugin list --json | "+home) {
			t.Fatalf("the check listed the plugins: %q", calls)
		}
	})
	t.Run("no package needed reads no plugin", func(t *testing.T) {
		f := fakeCodexPlugins(t, installedJSON(), marketsJSON(""))
		if got := check(f, nil, false); len(got) != 0 {
			t.Fatalf("problems = %q", got)
		}
	})
}

func TestBackupLegacySkillsMovesOnlyTheSkillsOfThePlugins(t *testing.T) {
	user := t.TempDir()
	t.Setenv("HOME", user)
	skillsDir := filepath.Join(user, ".agents", "skills")
	plugin := []string{"loupe-board", "loupe-stage-merge", "loupe-gone"}
	for _, name := range []string{"loupe-board", "loupe-stage-merge", "loupe-design-rebuild", "mine"} {
		if err := os.MkdirAll(filepath.Join(skillsDir, name), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(skillsDir, name, "SKILL.md"), []byte(name), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	backup := filepath.Join(t.TempDir(), "backup")
	now := time.Date(2026, 11, 3, 9, 30, 0, 0, time.UTC)

	if got := LegacySkillFolders(plugin); len(got) != 2 {
		t.Fatalf("legacy = %v", got)
	}
	dest, err := BackupLegacySkills(backup, plugin, now)
	if err != nil || dest != filepath.Join(backup, "2026-11-03") {
		t.Fatalf("backup = %q, %v", dest, err)
	}
	for _, name := range []string{"loupe-board", "loupe-stage-merge"} {
		if _, err := os.Stat(filepath.Join(dest, name, "SKILL.md")); err != nil {
			t.Fatalf("%s was not moved: %v", name, err)
		}
		if _, err := os.Stat(filepath.Join(skillsDir, name)); !os.IsNotExist(err) {
			t.Fatalf("%s stays: %v", name, err)
		}
	}
	for _, name := range []string{"mine", "loupe-design-rebuild"} {
		if _, err := os.Stat(filepath.Join(skillsDir, name)); err != nil {
			t.Fatalf("%s moved, and no plugin replaces it: %v", name, err)
		}
	}
	if again, err := BackupLegacySkills(backup, plugin, now); err != nil || again != "" {
		t.Fatalf("a second move = %q, %v", again, err)
	}
}
