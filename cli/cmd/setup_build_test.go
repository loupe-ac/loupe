package cmd

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/setupbuild"
	"github.com/loupe-ac/loupe/cli/internal/update"
)

type setupExecCall struct {
	path string
	argv []string
	env  []string
}

// recordExec replaces the hand-off and records what it was asked to run.
func recordExec(t *testing.T) *[]setupExecCall {
	t.Helper()
	t.Setenv("LOUPE_CONFIG", "")
	var calls []setupExecCall
	old := execBinary
	execBinary = func(path string, argv, env []string) error {
		calls = append(calls, setupExecCall{path, argv, env})

		return nil
	}
	t.Cleanup(func() {
		execBinary = old
		os.Unsetenv("LOUPE_CONFIG")
		_, _ = config.Resolve("")
		setupPin = ""
	})

	return &calls
}

// builtSetupDir writes a setup whose bin/loupe has body, with a matching record.
func builtSetupDir(t *testing.T, body string) string {
	t.Helper()
	x := filepath.Join(t.TempDir(), "my setup")
	if err := os.MkdirAll(filepath.Join(x, "bin"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(setupbuild.BinPath(x), []byte(body), 0o755); err != nil {
		t.Fatal(err)
	}
	writeBuildRecord(t, x, body)

	return x
}

func writeBuildRecord(t *testing.T, x, body string) {
	t.Helper()
	sum := sha256.Sum256([]byte(body))
	b, _ := json.Marshal(setupbuild.Record{Checkout: "/co", Commit: "abc123", Size: int64(len(body)), SHA256: hex.EncodeToString(sum[:])})
	if err := os.WriteFile(setupbuild.RecordPath(x), b, 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestABuiltSetupHandsOverToItsBinaryWithTheSameArguments(t *testing.T) {
	calls := recordExec(t)
	x := builtSetupDir(t, "#!/bin/sh\n")

	if err := resolveSetup([]string{"-c", x, "status", "--project", "p"}); err != nil {
		t.Fatal(err)
	}
	if len(*calls) != 1 {
		t.Fatalf("exec calls = %d", len(*calls))
	}
	got := (*calls)[0]
	if got.path != setupbuild.BinPath(x) || !slices.Equal(got.argv[1:], os.Args[1:]) || got.argv[0] != got.path {
		t.Fatalf("exec = %+v", got)
	}
	if !slices.Contains(got.env, "LOUPE_CONFIG="+x) {
		t.Fatalf("env lacks LOUPE_CONFIG")
	}
}

func TestABuiltSetupRefusesAWrongBuildBeforeHelpAndVersion(t *testing.T) {
	calls := recordExec(t)
	x := builtSetupDir(t, "#!/bin/sh\n")
	if err := os.WriteFile(setupbuild.BinPath(x), []byte("#!/bin/sh\necho evil\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	want := setupbuild.BinPath(x) + " is not the build that just cli-setup checked; run just cli-setup " + x

	for _, args := range [][]string{{"-c", x, "--version"}, {"-c", x, "--help"}, {"-c", x, "status"}, {"-c", x, "help", "status"}} {
		err := resolveSetup(args)
		if err == nil || !strings.HasPrefix(err.Error(), want) {
			t.Errorf("%v: err = %v", args, err)
		}
	}
	if len(*calls) != 0 {
		t.Fatal("a wrong build was handed the run")
	}
}

func TestABuiltSetupRefusesAMissingRecordOrBinary(t *testing.T) {
	calls := recordExec(t)
	x := builtSetupDir(t, "#!/bin/sh\n")
	_ = os.Remove(setupbuild.RecordPath(x))
	if err := resolveSetup([]string{"-c", x, "status"}); err == nil {
		t.Error("a binary with no record must be refused")
	}

	x = builtSetupDir(t, "#!/bin/sh\n")
	_ = os.Remove(setupbuild.BinPath(x))
	if err := resolveSetup([]string{"-c", x, "status"}); err == nil {
		t.Error("a record with no binary must not fall back to the shared binary")
	}

	x = builtSetupDir(t, "#!/bin/sh\n")
	_ = os.Chmod(setupbuild.BinPath(x), 0o775)
	if err := resolveSetup([]string{"-c", x, "status"}); err == nil {
		t.Error("a binary a group can write must be refused")
	}
	if len(*calls) != 0 {
		t.Fatal("something ran")
	}
}

func TestTheDefaultSetupRunsNothingElse(t *testing.T) {
	calls := recordExec(t)
	t.Setenv("LOUPE_CONFIG", "")
	for _, args := range [][]string{{"--version"}, {"--help"}, {"status"}} {
		if err := resolveSetup(args); err != nil {
			t.Errorf("%v: %v", args, err)
		}
	}
	if len(*calls) != 0 {
		t.Fatal("the default setup handed the run over")
	}
}

func TestSetupProbeSkipsTheCheck(t *testing.T) {
	calls := recordExec(t)
	x := builtSetupDir(t, "#!/bin/sh\n")
	_ = os.Remove(setupbuild.RecordPath(x))

	if err := resolveSetup([]string{"-c", x, "setup-probe"}); err != nil {
		t.Fatalf("setup-probe was refused: %v", err)
	}
	if len(*calls) != 0 {
		t.Fatal("setup-probe was handed over")
	}
}

// The binary that runs is the binary of the setup, so the run goes on and the
// target does not hand over again. The record is hashed from the running test
// binary through a hard link.
func TestTheTargetOfTheHandOverRunsAndDoesNotHandOverAgain(t *testing.T) {
	calls := recordExec(t)
	exe, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	x := filepath.Join(filepath.Dir(exe), "setup-link-"+filepath.Base(t.TempDir()))
	if err := os.MkdirAll(filepath.Join(x, "bin"), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(x) })
	if err := os.Link(exe, setupbuild.BinPath(x)); err != nil {
		t.Skipf("no hard link to the test binary: %v", err)
	}
	body, err := os.ReadFile(exe)
	if err != nil {
		t.Fatal(err)
	}
	writeBuildRecord(t, x, string(body))

	if err := resolveSetup([]string{"-c", x, "status"}); err != nil {
		t.Fatalf("the target was refused: %v", err)
	}
	if len(*calls) != 0 {
		t.Fatal("the target handed over to itself")
	}

	writeBuildRecord(t, x, "other")
	if err := resolveSetup([]string{"-c", x, "status"}); err == nil {
		t.Fatal("a direct start whose hash differs must be refused")
	}
}

func TestChildrenGetThePinFirstOnPathAndNeverTheBin(t *testing.T) {
	recordExec(t)
	x := builtSetupDir(t, "#!/bin/sh\n")
	if _, err := config.Resolve(x); err != nil {
		t.Fatal(err)
	}
	pin, err := makeSetupPin()
	if err != nil || pin == "" {
		t.Fatalf("pin = %q, %v", pin, err)
	}

	env := workerEnv([]string{"PATH=/usr/bin:/bin"}, "s1", "", nil, nil)
	if !slices.Contains(env, "PATH="+pin+":/usr/bin:/bin") {
		t.Fatalf("worker PATH wrong: %q", env)
	}
	if !slices.Contains(withSetupEnv([]string{"PATH=/usr/bin"}), "PATH="+pin+":/usr/bin") {
		t.Fatal("the launch script PATH lacks the pin")
	}
	for _, e := range append(env, withSetupEnv(nil)...) {
		if strings.Contains(e, filepath.Join(x, "bin")) {
			t.Fatalf("%q names the bin folder of the setup", e)
		}
	}
	if !setupbuild.InPin(x, filepath.Join(pin, "loupe")) {
		t.Fatal("the pin is outside tmp/pin")
	}
}

func TestStatusPrintsTheBinaryOfASetup(t *testing.T) {
	recordExec(t)
	x := builtSetupDir(t, "#!/bin/sh\n")
	setup, err := config.Resolve(x)
	if err != nil {
		t.Fatal(err)
	}
	got := binaryDescription(setup)
	if !strings.Contains(got, "/co") || !strings.Contains(got, "abc123") {
		t.Fatalf("binary = %q", got)
	}
}

func TestUpdateOfABuiltSetupNamesTheCheckoutAndTheRecipe(t *testing.T) {
	recordExec(t)
	x := builtSetupDir(t, "#!/bin/sh\n")
	if _, err := config.Resolve(x); err != nil {
		t.Fatal(err)
	}

	_, err := updateCmd(t, noSelfUpdate(t))
	if err == nil {
		t.Fatal("a built setup must not update")
	}
	for _, want := range []string{"/co", "abc123", "just cli-setup " + x} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("err lacks %q: %v", want, err)
		}
	}
}

func TestUpdateOfASetupOnTheSharedBinaryLeavesTheBinary(t *testing.T) {
	recordExec(t)
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0", "cli/v1.2.0")
	self, exe := selfUpdateAgainst(t, gh, "1.0.0")
	if _, err := config.Resolve(filepath.Join(t.TempDir(), "setup")); err != nil {
		t.Fatal(err)
	}

	out, err := updateCmd(t, self)
	if err != nil {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if body, _ := os.ReadFile(exe); string(body) != "old" {
		t.Fatalf("the shared binary changed to %q", body)
	}
	if !strings.Contains(out, "updates from the default setup") {
		t.Fatalf("out = %q", out)
	}
}

// The bridge of a setup runs no updater and a Homebrew binary stays with brew,
// so the update of a setup installs its panel and asks neither.
func TestUpdateOfASetupWithARunningBridgeOnAHomebrewBinaryInstallsThePanel(t *testing.T) {
	recordExec(t)
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0", "cli/v1.2.0")
	self, _ := selfUpdateAgainst(t, gh, "1.0.0")
	keg, link := homebrewBinary(t)
	self.executable = func() (string, error) { return link, nil }
	x, err := os.MkdirTemp("/tmp", "lx")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(x) })
	if _, err := config.Resolve(x); err != nil {
		t.Fatal(err)
	}
	rules, err := defaultRulesPath()
	if err != nil {
		t.Fatal(err)
	}
	serveUpdateTest(t, rules, func(context.Context, func(updateResult)) updateResult {
		t.Error("the bridge of the setup was asked")

		return updateResult{}
	})

	out, err := updateCmd(t, self)
	if err != nil {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if !strings.Contains(out, "updates from the default setup") {
		t.Fatalf("out = %q", out)
	}
	if data, _ := os.ReadFile(keg); string(data) != "old" {
		t.Fatalf("binary = %q", data)
	}
}

func TestReviewIOSInABuiltSetupUsesItsPanelItsEnvAndThePin(t *testing.T) {
	recordExec(t)
	h := newIOSHarness(t)
	x := builtSetupDir(t, "#!/bin/sh\n")
	if _, err := config.Resolve(x); err != nil {
		t.Fatal(err)
	}
	h.installPanel(t, filepath.Join(x, "libexec", update.PanelAppName))

	if _, err := h.run(); err != nil {
		t.Fatal(err)
	}
	got := h.opened[0]
	pin, _ := makeSetupPin()
	want := []string{"-n", "--env", "LOUPE_CONFIG=" + x, filepath.Join(x, "libexec", update.PanelAppName), "--args", "--loupe", filepath.Join(pin, "loupe")}
	if !slices.Equal(got[:len(want)], want) {
		t.Fatalf("open = %q, want it to start with %q", got, want)
	}
}

func TestReviewIOSInABuiltSetupWithNoPanelNamesTheRecipe(t *testing.T) {
	recordExec(t)
	h := newIOSHarness(t)
	x := builtSetupDir(t, "#!/bin/sh\n")
	if _, err := config.Resolve(x); err != nil {
		t.Fatal(err)
	}

	_, err := h.run()
	if err == nil || !strings.Contains(err.Error(), "just cli-setup "+x) {
		t.Fatalf("err = %v", err)
	}
}

func TestReviewIOSOnTheSharedBinaryFallsBackToThePanelOfTheDefaultSetup(t *testing.T) {
	recordExec(t)
	h := newIOSHarness(t)
	home := t.TempDir()
	t.Setenv("HOME", home)
	t.Setenv("XDG_CONFIG_HOME", home)
	defaultDir, err := config.DefaultDir()
	if err != nil {
		t.Fatal(err)
	}
	panel := filepath.Join(update.PanelDir(defaultDir, "1.2.3"), update.PanelAppName)
	h.installPanel(t, panel)
	if _, err := config.Resolve(filepath.Join(t.TempDir(), "setup")); err != nil {
		t.Fatal(err)
	}

	if _, err := h.run(); err != nil {
		t.Fatal(err)
	}
	if got := h.opened[0]; got[3] != panel {
		t.Fatalf("open = %q, want the panel %s", got, panel)
	}
}
