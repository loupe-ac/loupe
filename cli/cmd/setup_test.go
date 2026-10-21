package cmd

import (
	"bytes"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/zalando/go-keyring"
)

func TestEarlyConfigFlag(t *testing.T) {
	cases := []struct {
		args []string
		want string
	}{
		{[]string{"-c", "X", "--version"}, "X"},
		{[]string{"-c", "X", "--help"}, "X"},
		{[]string{"status", "-h", "-c", "X"}, "X"},
		{[]string{"status", "--help", "--config=X"}, "X"},
		{[]string{"-c", "X", "help", "status"}, "X"},
		{[]string{"status", "--prompt", "-c"}, ""},
		{[]string{"status"}, ""},
		{nil, ""},
	}
	for _, tc := range cases {
		if got := earlyConfigFlag(tc.args); got != tc.want {
			t.Errorf("earlyConfigFlag(%q) = %q, want %q", tc.args, got, tc.want)
		}
	}
}

func resetSetupAfter(t *testing.T) {
	t.Helper()
	t.Cleanup(func() { _, _ = config.Resolve("") })
}

func TestResolveSetupWritesTheEnvironment(t *testing.T) {
	resetSetupAfter(t)
	t.Setenv("LOUPE_CONFIG", "")
	t.Setenv("HOME", t.TempDir())
	dir := filepath.Join(t.TempDir(), "x")

	if err := resolveSetup([]string{"-c", dir, "--version"}); err != nil {
		t.Fatal(err)
	}
	if got := os.Getenv("LOUPE_CONFIG"); got != dir {
		t.Fatalf("LOUPE_CONFIG = %q, want %q", got, dir)
	}

	t.Setenv("LOUPE_CONFIG", "")
	if err := resolveSetup([]string{"status"}); err != nil {
		t.Fatal(err)
	}
	if got := os.Getenv("LOUPE_CONFIG"); got != "" {
		t.Fatalf("the default setup must not set LOUPE_CONFIG, got %q", got)
	}
}

func TestSetupProbePrintsTheResolvedFolder(t *testing.T) {
	resetSetupAfter(t)
	t.Setenv("LOUPE_CONFIG", "")
	dir := filepath.Join(t.TempDir(), "x")
	if _, err := config.Resolve(dir); err != nil {
		t.Fatal(err)
	}

	root := newRootCmd()
	var out bytes.Buffer
	root.SetOut(&out)
	root.SetArgs([]string{"-c", dir, "setup-probe"})
	if err := root.Execute(); err != nil {
		t.Fatal(err)
	}
	if got := out.String(); got != dir+"\n" {
		t.Fatalf("setup-probe printed %q", got)
	}
}

func TestStatusPrintsTheSetup(t *testing.T) {
	resetSetupAfter(t)
	t.Setenv("LOUPE_CONFIG", "")
	dir := t.TempDir()
	if _, err := config.Resolve(dir); err != nil {
		t.Fatal(err)
	}

	root := newRootCmd()
	var out bytes.Buffer
	root.SetOut(&out)
	root.SetErr(&bytes.Buffer{})
	root.SetArgs([]string{"status"})
	_ = root.Execute()
	if want := "Setup:       " + dir + " (-c)\n"; !bytes.Contains(out.Bytes(), []byte(want)) {
		t.Fatalf("status output lacks %q:\n%s", want, out.String())
	}
}

// A setup other than the default writes nothing to the shared temp folder.
func TestSetupWritesNothingToTheSharedTempFolder(t *testing.T) {
	keyring.MockInit()
	resetSetupAfter(t)
	shared := t.TempDir()
	t.Setenv("TMPDIR", shared)
	t.Setenv("LOUPE_CONFIG", "")
	dir := filepath.Join(t.TempDir(), "setup")
	if _, err := config.Resolve(dir); err != nil {
		t.Fatal(err)
	}

	cfg := config.Config{BaseURL: "https://example.test", OAuth: &config.OAuthTokens{AccessToken: "a", RefreshToken: "r", ExpiresAt: time.Now().Add(time.Hour)}}
	if err := config.Save(cfg); err != nil {
		t.Fatal(err)
	}
	script, err := writeLaunchScript(defaultScriptDir(), "s1", "true\n")
	if err != nil {
		t.Fatal(err)
	}
	tmp, err := config.TempDir()
	if err != nil {
		t.Fatal(err)
	}
	f, err := os.CreateTemp(tmp, "loupe-bridge-start-*")
	if err != nil {
		t.Fatal(err)
	}
	f.Close()

	if entries, _ := os.ReadDir(shared); len(entries) != 0 {
		t.Fatalf("the shared temp folder holds %d entries", len(entries))
	}
	if filepath.Dir(script) != filepath.Join(dir, "tmp", "loupe-sessions") {
		t.Fatalf("launch script at %s, want under %s", script, filepath.Join(dir, "tmp"))
	}
	if got := defaultLogPath(); got != filepath.Join(dir, "bridge.log") {
		t.Fatalf("defaultLogPath = %q", got)
	}
}
