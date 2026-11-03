package skills

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

func scriptPath(t *testing.T, name string) string {
	t.Helper()
	if _, err := exec.LookPath("sh"); err != nil {
		t.Skip("sh is not available")
	}
	if _, err := exec.LookPath("git"); err != nil {
		t.Skip("git is not available")
	}
	script, err := filepath.Abs(filepath.Join("..", "..", "scripts", name))
	if err != nil {
		t.Fatal(err)
	}

	return script
}

func git(t *testing.T, dir string, args ...string) {
	t.Helper()
	cmd := exec.Command("git", append([]string{"-c", "user.name=test", "-c", "user.email=test@example.com"}, args...)...)
	cmd.Dir = dir
	cmd.Env = append(os.Environ(), "GIT_CONFIG_GLOBAL="+os.DevNull, "GIT_CONFIG_NOSYSTEM=1")
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("git %v: %v\n%s", args, err, out)
	}
}

func writeManifests(t *testing.T, repo, plugin, version, codexVersion string) {
	t.Helper()
	for folder, v := range map[string]string{".claude-plugin": version, ".codex-plugin": codexVersion} {
		dir := filepath.Join(repo, "plugins", plugin, folder)
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatal(err)
		}
		content := fmt.Sprintf("{\n  \"name\": %q,\n  \"version\": %q\n}\n", plugin, v)
		if err := os.WriteFile(filepath.Join(dir, "plugin.json"), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
}

func TestPluginVersionCheck(t *testing.T) {
	script := scriptPath(t, "plugin-version-check.sh")

	cases := []struct {
		name    string
		version string
		codex   string
		wantErr string
	}{
		{name: "a greater patch passes", version: "0.56.1", codex: "0.56.1"},
		{name: "a greater major passes", version: "1.0.0", codex: "1.0.0"},
		{name: "numbers compare as numbers", version: "0.100.0", codex: "0.100.0"},
		{name: "the same version fails", version: "0.56.0", codex: "0.56.0", wantErr: "not greater than 0.56.0"},
		{name: "a lower version fails", version: "0.54.1", codex: "0.54.1", wantErr: "not greater than 0.56.0"},
		{name: "another form fails", version: "0.57.0-rc1", codex: "0.57.0-rc1", wantErr: "is not a version"},
		{name: "another Codex version fails", version: "0.57.0", codex: "0.56.0", wantErr: "for Codex"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			repo := t.TempDir()
			git(t, repo, "init", "-b", "main")
			writeManifests(t, repo, "loupe", "0.56.0", "0.56.0")
			git(t, repo, "add", ".")
			git(t, repo, "commit", "-m", "base")
			git(t, repo, "switch", "-c", "change")
			writeManifests(t, repo, "loupe", tc.version, tc.codex)
			if err := os.WriteFile(filepath.Join(repo, "plugins", "loupe", "skill.md"), []byte("change"), 0o644); err != nil {
				t.Fatal(err)
			}
			git(t, repo, "add", ".")
			git(t, repo, "commit", "-m", "change")

			cmd := exec.Command("sh", script, "main")
			cmd.Dir = repo
			out, err := cmd.CombinedOutput()
			if tc.wantErr == "" {
				if err != nil {
					t.Fatalf("the check failed: %v\n%s", err, out)
				}

				return
			}
			if err == nil {
				t.Fatalf("the check passed, want an error with %q", tc.wantErr)
			}
			if !strings.Contains(string(out), tc.wantErr) {
				t.Fatalf("output = %s, want %q", out, tc.wantErr)
			}
		})
	}
}

func TestPluginVersionCheckPassesANewAndAnUnchangedPlugin(t *testing.T) {
	script := scriptPath(t, "plugin-version-check.sh")
	repo := t.TempDir()
	git(t, repo, "init", "-b", "main")
	writeManifests(t, repo, "loupe", "0.56.0", "0.56.0")
	git(t, repo, "add", ".")
	git(t, repo, "commit", "-m", "base")
	git(t, repo, "switch", "-c", "change")
	writeManifests(t, repo, "loupe-lifecycle", "0.1.0", "0.1.0")
	git(t, repo, "add", ".")
	git(t, repo, "commit", "-m", "new plugin")

	cmd := exec.Command("sh", script, "main")
	cmd.Dir = repo
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("the check failed: %v\n%s", err, out)
	}
}

func TestSkillsTagCheck(t *testing.T) {
	script := scriptPath(t, "skills-tag-check.sh")

	cases := []struct {
		name    string
		tags    []string
		version string
		wantErr bool
	}{
		{name: "the first release passes", version: "0.1.0"},
		{name: "a greater version passes", tags: []string{"skills/loupe/v0.56.0"}, version: "0.56.1"},
		{name: "an existing version fails", tags: []string{"skills/loupe/v0.56.0"}, version: "0.56.0", wantErr: true},
		{name: "a lower version fails", tags: []string{"skills/loupe/v0.56.0"}, version: "0.54.1", wantErr: true},
		{name: "the highest tag counts", tags: []string{"skills/loupe/v0.9.0", "skills/loupe/v0.10.0"}, version: "0.9.1", wantErr: true},
		{name: "another package does not count", tags: []string{"skills/loupe-lifecycle/v9.0.0"}, version: "0.1.0"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			repo := t.TempDir()
			git(t, repo, "init", "-b", "main")
			git(t, repo, "commit", "--allow-empty", "-m", "base")
			for _, tag := range tc.tags {
				git(t, repo, "tag", tag)
			}

			cmd := exec.Command("sh", script, "loupe", tc.version)
			cmd.Dir = repo
			out, err := cmd.CombinedOutput()
			if tc.wantErr && err == nil {
				t.Fatalf("the check passed, want a refusal\n%s", out)
			}
			if !tc.wantErr && err != nil {
				t.Fatalf("the check failed: %v\n%s", err, out)
			}
		})
	}
}
