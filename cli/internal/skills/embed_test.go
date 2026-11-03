package skills

import (
	"embed"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
)

// embed.FS reports every file as 0444, and fstest.MapFS keeps the mode it is
// given, so only a real embed shows what a release binary does.
//
//go:embed all:testdata/bundle
var embeddedTestBundle embed.FS

func TestInstallFromAnEmbedKeepsExecutablePaths(t *testing.T) {
	dir := t.TempDir()

	if _, err := install(embeddedTestBundle, "testdata/bundle", dir); err != nil {
		t.Fatal(err)
	}
	store := filepath.Join(dir, "skills", "demo", "1.0.0")

	hook, err := os.Stat(filepath.Join(store, "hooks", "run.sh"))
	if err != nil {
		t.Fatal(err)
	}
	if hook.Mode().Perm()&0o100 == 0 {
		t.Fatalf("hook mode = %v, want executable", hook.Mode())
	}
	if out, err := exec.Command(filepath.Join(store, "hooks", "run.sh")).CombinedOutput(); err != nil {
		t.Fatalf("hook does not run: %v\n%s", err, out)
	}

	skill, err := os.Stat(filepath.Join(store, "skills", "SKILL.md"))
	if err != nil {
		t.Fatal(err)
	}
	if skill.Mode().Perm()&0o111 != 0 {
		t.Fatalf("skill mode = %v, want not executable", skill.Mode())
	}

	if _, err := os.Stat(filepath.Join(store, executablesPath)); !os.IsNotExist(err) {
		t.Fatalf("the store holds the list of executable paths: %v", err)
	}
}

func TestInstallRefusesAPackageWithNoExecutableList(t *testing.T) {
	bundle := fakeBundle()
	delete(bundle, "bundle/loupe/"+executablesPath)

	if _, err := install(bundle, "bundle", t.TempDir()); err == nil {
		t.Fatal("want an error")
	}
}

func TestBundleScriptListsExecutablePaths(t *testing.T) {
	script := scriptPath(t, "bundle-skills.sh")
	root := t.TempDir()
	hooks := filepath.Join(root, "plugins", "demo", "hooks")
	dest := filepath.Join(root, "cli", "internal", "skills", "bundle")
	for _, dir := range []string{hooks, dest} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	for name, mode := range map[string]os.FileMode{"b.sh": 0o755, "a.sh": 0o755, "notes.md": 0o644} {
		if err := os.WriteFile(filepath.Join(hooks, name), []byte("x"), mode); err != nil {
			t.Fatal(err)
		}
	}

	if out, err := exec.Command("sh", script, root).CombinedOutput(); err != nil {
		t.Fatalf("bundle-skills.sh: %v\n%s", err, out)
	}

	list, err := os.ReadFile(filepath.Join(dest, "demo", executablesPath))
	if err != nil {
		t.Fatal(err)
	}
	if want := "hooks/a.sh\nhooks/b.sh\n"; string(list) != want {
		t.Fatalf("list = %q, want %q", list, want)
	}
}
