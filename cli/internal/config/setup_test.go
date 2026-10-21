package config

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/zalando/go-keyring"
)

func resetSetup(t *testing.T) {
	t.Helper()
	t.Cleanup(func() { _, _ = Resolve("") })
}

func TestResolvePrecedenceAndPaths(t *testing.T) {
	useTempConfigHome(t)
	resetSetup(t)
	home := os.Getenv("HOME")
	cwd, _ := os.Getwd()

	t.Setenv("LOUPE_CONFIG", "/from/env")
	s, err := Resolve("/from/flag")
	if err != nil || s != (Setup{Dir: "/from/flag", Source: SourceFlag}) {
		t.Fatalf("flag must win over env, got %+v, %v", s, err)
	}
	if got, _ := Dir(); got != "/from/flag" {
		t.Fatalf("Dir = %q", got)
	}

	s, _ = Resolve("")
	if s != (Setup{Dir: "/from/env", Source: SourceEnv}) {
		t.Fatalf("env: %+v", s)
	}

	s, _ = Resolve("~/x")
	if s.Dir != filepath.Join(home, "x") {
		t.Fatalf("tilde: %+v", s)
	}
	s, _ = Resolve("rel/dir")
	if s.Dir != filepath.Join(cwd, "rel/dir") {
		t.Fatalf("relative: %+v", s)
	}

	t.Setenv("LOUPE_CONFIG", "")
	s, _ = Resolve("")
	def, _ := DefaultDir()
	if s != (Setup{Dir: def, Source: SourceDefault}) {
		t.Fatalf("default: %+v", s)
	}
}

func TestCurrentFollowsTheEnvironmentBeforeResolve(t *testing.T) {
	useTempConfigHome(t)
	resetSetup(t)
	t.Setenv("LOUPE_CONFIG", "/child/setup")

	if got := Current(); got != (Setup{Dir: "/child/setup", Source: SourceEnv}) {
		t.Fatalf("Current = %+v", got)
	}
}

func TestTempDir(t *testing.T) {
	useTempConfigHome(t)
	resetSetup(t)

	got, err := TempDir()
	if err != nil || got != os.TempDir() {
		t.Fatalf("default TempDir = %q, %v", got, err)
	}

	dir := filepath.Join(t.TempDir(), "setup")
	_, _ = Resolve(dir)
	got, err = TempDir()
	if err != nil || got != filepath.Join(dir, "tmp") {
		t.Fatalf("TempDir = %q, %v", got, err)
	}
	info, err := os.Stat(got)
	if err != nil || info.Mode().Perm() != 0o700 {
		t.Fatalf("tmp folder: %v, %v", info, err)
	}
}

func TestLoadNamesTheSetupAndKeepsErrNotLoggedIn(t *testing.T) {
	useTempConfigHome(t)
	resetSetup(t)

	if _, err := Load(); err != ErrNotLoggedIn {
		t.Fatalf("default setup must return the bare error, got %v", err)
	}

	dir := t.TempDir()
	_, _ = Resolve(dir)
	_, err := Load()
	if !errors.Is(err, ErrNotLoggedIn) {
		t.Fatalf("errors.Is lost: %v", err)
	}
	want := "not logged in to the setup " + dir + " (chosen by -c): run `loupe -c " + dir + " login`"
	if err.Error() != want {
		t.Fatalf("message = %q, want %q", err.Error(), want)
	}
}

func TestSaveSkipsTheKeychainOutsideTheDefaultSetup(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	resetSetup(t)
	const base = "https://example.test"
	if err := keyring.Set(keyringService, base, "old"); err != nil {
		t.Fatal(err)
	}
	cfg := oauthLogin("a", "r", time.Now().Add(time.Hour))
	cfg.BaseURL = base

	_, _ = Resolve(t.TempDir())
	if err := Save(cfg); err != nil {
		t.Fatal(err)
	}
	if _, err := keyring.Get(keyringService, base); err != nil {
		t.Fatalf("a second setup deleted the keychain entry: %v", err)
	}

	_, _ = Resolve("")
	if err := Save(cfg); err != nil {
		t.Fatal(err)
	}
	if _, err := keyring.Get(keyringService, base); err == nil {
		t.Fatal("the default setup must still clear the keychain entry")
	}
}
