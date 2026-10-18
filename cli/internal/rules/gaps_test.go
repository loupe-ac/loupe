package rules

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func gapsOf(t *testing.T, body, dir string) string {
	t.Helper()
	gaps, err := Gaps([]byte(body), dir)
	if err != nil {
		t.Fatalf("Gaps: %v", err)
	}

	return strings.Join(gaps, "\n")
}

func TestGapsFindsNothingInAFirstRunFile(t *testing.T) {
	dir := t.TempDir()
	data, err := FirstRun(FirstRunOptions{Slug: "app", Dir: dir, GOOS: "darwin", LookPath: func(string) (string, error) { return "", os.ErrNotExist }})
	if err != nil {
		t.Fatal(err)
	}
	if got := gapsOf(t, string(data), dir); got != "" {
		t.Errorf("a first-run file has gaps:\n%s", got)
	}
}

func TestGapsNamesAMissingPermissionDefault(t *testing.T) {
	dir := t.TempDir()
	body := "appPrompts: true\nprojects:\n  app:\n    dir: " + dir + "\n"
	got := gapsOf(t, body, dir)
	if !strings.Contains(got, "permission") || strings.Contains(got, "prompts") || strings.Contains(got, "maps") {
		t.Errorf("want the permission gap alone, got:\n%s", got)
	}
}

func TestGapsAcceptsAnAccountPermissionMode(t *testing.T) {
	dir := t.TempDir()
	body := "appPrompts: true\naccounts:\n  a:\n    harness: claude-code\n    permissionMode: auto\nprojects:\n  app:\n    dir: " + dir + "\n"
	if got := gapsOf(t, body, dir); got != "" {
		t.Errorf("got gaps:\n%s", got)
	}
}

func TestGapsNamesMissingPrompts(t *testing.T) {
	dir := t.TempDir()
	body := "appPrompts: false\ndefaults:\n  permissions: workspace\nprojects:\n  app:\n    dir: " + dir + "\n"
	if got := gapsOf(t, body, dir); !strings.Contains(got, "no prompts") {
		t.Errorf("want the prompts gap, got:\n%s", got)
	}
	withWork := body + "work:\n  fix:\n    prompt: go\n"
	if got := gapsOf(t, withWork, dir); got != "" {
		t.Errorf("a work entry is a prompt, got:\n%s", got)
	}
}

func TestGapsMatchesTheDirectoryThroughASymlink(t *testing.T) {
	real := t.TempDir()
	link := filepath.Join(t.TempDir(), "link")
	if err := os.Symlink(real, link); err != nil {
		t.Fatal(err)
	}
	body := "appPrompts: true\ndefaults:\n  permissions: workspace\nprojects:\n  app:\n    dir: " + link + "\n"
	if got := gapsOf(t, body, real); got != "" {
		t.Errorf("a symlinked dir must match, got:\n%s", got)
	}
}

func TestGapsExpandsTheHomeDirectory(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)
	dir := filepath.Join(home, "repo")
	if err := os.Mkdir(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	body := "appPrompts: true\ndefaults:\n  permissions: workspace\nprojects:\n  app:\n    dir: ~/repo\n"
	if got := gapsOf(t, body, dir); got != "" {
		t.Errorf("~ must expand, got:\n%s", got)
	}
}

func TestGapsNamesAnUnmappedDirectory(t *testing.T) {
	body := "appPrompts: true\ndefaults:\n  permissions: workspace\nprojects:\n  app:\n    dir: " + t.TempDir() + "\n"
	if got := gapsOf(t, body, t.TempDir()); !strings.Contains(got, "no project maps") {
		t.Errorf("want the project gap, got:\n%s", got)
	}
}

func TestGapsChecksOnlyTheLinkedProject(t *testing.T) {
	dir := t.TempDir()
	body := "appPrompts: true\ndefaults:\n  permissions: workspace\nprojects:\n  b:\n    dir: " + dir + "\n"
	gaps, err := Gaps([]byte(body), dir, "a", "01a0c0d9-905c-7922-a586-ccc8ce043704")
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(gaps, "\n"); !strings.Contains(got, "project a does not map") {
		t.Errorf("another project at the directory must not count, got:\n%s", got)
	}
	if gaps, _ := Gaps([]byte(body), dir, "B", "a"); len(gaps) != 0 {
		t.Errorf("the first key matches, got %v", gaps)
	}
	gaps, _ = Gaps([]byte(body), dir, "a", "B")
	if got := strings.Join(gaps, "\n"); !strings.Contains(got, "project a is mapped under projects.b, and the bridge takes only projects.a") {
		t.Errorf("an entry under another handle must be named, got:\n%s", got)
	}
}

func TestGapsRefusesAFileThatDoesNotParse(t *testing.T) {
	if _, err := Gaps([]byte("a: [\n"), t.TempDir()); err == nil {
		t.Error("want an error")
	}
}
