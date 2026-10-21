package cmd

import (
	"bytes"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/update"
)

const iosProject = "01a0c0d9-905c-7922-a586-ccc8ce043704"

type iosHarness struct {
	env     reviewIOS
	root    string
	cwd     string
	exe     string
	opened  [][]string
	openErr error
}

// newIOSHarness builds a macOS install of loupe 1.2.3 with the panel in the
// config directory, and an empty working folder.
func newIOSHarness(t *testing.T) *iosHarness {
	t.Helper()
	root := t.TempDir()
	h := &iosHarness{root: root, cwd: filepath.Join(root, "work"), exe: filepath.Join(root, "bin", "loupe")}
	for _, d := range []string{h.cwd, filepath.Dir(h.exe)} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.WriteFile(h.exe, []byte("x"), 0o755); err != nil {
		t.Fatal(err)
	}
	cfg := filepath.Join(root, "cfg")
	h.env = reviewIOS{
		goos:       "darwin",
		version:    "1.2.3",
		executable: func() (string, error) { return h.exe, nil },
		configDir:  func() (string, error) { return cfg, nil },
		getwd:      func() (string, error) { return h.cwd, nil },
		open: func(args ...string) error {
			h.opened = append(h.opened, args)

			return h.openErr
		},
	}
	h.installPanel(t, filepath.Join(update.PanelDir(cfg, "1.2.3"), update.PanelAppName))

	return h
}

func (h *iosHarness) installPanel(t *testing.T, app string) {
	t.Helper()
	if err := os.MkdirAll(app, 0o755); err != nil {
		t.Fatal(err)
	}
}

func (h *iosHarness) run() (string, error) {
	var out bytes.Buffer
	cmd := newReviewIOSCmdWith(h.env)
	cmd.SetOut(&out)
	cmd.SetErr(io.Discard)
	cmd.SetArgs(nil)
	err := cmd.Execute()

	return out.String(), err
}

func TestReviewIOSOpensThePanelWithTheBinaryAndTheFolder(t *testing.T) {
	h := newIOSHarness(t)

	out, err := h.run()
	if err != nil {
		t.Fatal(err)
	}
	app := filepath.Join(h.root, "cfg", "live-review", "1.2.3", "Live review.app")
	want := []string{"-n", app, "--args", "--loupe", h.exe, "--dir", h.cwd}
	if len(h.opened) != 1 || !slices.Equal(h.opened[0], want) {
		t.Fatalf("open = %v, want %v", h.opened, want)
	}
	if !strings.Contains(out, h.cwd) {
		t.Fatalf("out = %q", out)
	}
}

func TestReviewIOSPassesTheProjectOfTheProjectFile(t *testing.T) {
	h := newIOSHarness(t)
	if err := os.WriteFile(filepath.Join(h.cwd, ".loupe.yaml"), []byte("project: "+iosProject+"\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	if _, err := h.run(); err != nil {
		t.Fatal(err)
	}
	got := h.opened[0]
	if tail := got[len(got)-2:]; !slices.Equal(tail, []string{"--project", iosProject}) {
		t.Fatalf("open = %v", got)
	}
}

func TestReviewIOSPrefersThePanelInLibexec(t *testing.T) {
	h := newIOSHarness(t)
	keg := filepath.Join(h.root, "Cellar", "loupe", "1.2.3")
	if err := os.MkdirAll(filepath.Join(keg, "bin"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(keg, "bin", "loupe"), []byte("x"), 0o755); err != nil {
		t.Fatal(err)
	}
	app := filepath.Join(keg, "libexec", update.PanelAppName)
	h.installPanel(t, app)
	link := filepath.Join(h.root, "brew-bin", "loupe")
	if err := os.MkdirAll(filepath.Dir(link), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(keg, "bin", "loupe"), link); err != nil {
		t.Fatal(err)
	}
	h.exe = link

	if _, err := h.run(); err != nil {
		t.Fatal(err)
	}
	resolvedApp, _ := filepath.EvalSymlinks(app)
	gotApp, _ := filepath.EvalSymlinks(h.opened[0][1])
	if gotApp != resolvedApp {
		t.Fatalf("app = %q, want %q", h.opened[0][1], app)
	}
	if h.opened[0][4] != link {
		t.Fatalf("binary = %q, want the symlink %q", h.opened[0][4], link)
	}
}

func TestReviewIOSNeedsMacOS(t *testing.T) {
	h := newIOSHarness(t)
	h.env.goos = "linux"

	_, err := h.run()
	if err == nil || err.Error() != "Live review for iOS needs macOS" || len(h.opened) != 0 {
		t.Fatalf("err = %v, opened = %v", err, h.opened)
	}
}

func TestReviewIOSNamesBothPlacesWhenThePanelIsMissing(t *testing.T) {
	h := newIOSHarness(t)
	h.env.version = "1.2.4"

	_, err := h.run()
	if err == nil || len(h.opened) != 0 {
		t.Fatalf("err = %v, opened = %v", err, h.opened)
	}
	for _, want := range []string{"libexec", filepath.Join("live-review", "1.2.4", "Live review.app"), "loupe update", "reinstall"} {
		if !strings.Contains(err.Error(), want) {
			t.Fatalf("err = %q, want %q", err, want)
		}
	}
}

func TestReviewIOSSaysSoForADevelopmentBuild(t *testing.T) {
	h := newIOSHarness(t)
	h.env.version = ""

	_, err := h.run()
	if err == nil || !strings.Contains(err.Error(), "development build") || len(h.opened) != 0 {
		t.Fatalf("err = %v, opened = %v", err, h.opened)
	}
}

func TestReviewIOSReportsAFailedOpen(t *testing.T) {
	h := newIOSHarness(t)
	h.openErr = errors.New("exit status 1: no such app")

	_, err := h.run()
	if err == nil || !strings.Contains(err.Error(), "could not open") || !strings.Contains(err.Error(), "no such app") {
		t.Fatalf("err = %v", err)
	}
}

func TestReviewIOSReportsAnUnreadableProjectFile(t *testing.T) {
	h := newIOSHarness(t)
	if err := os.WriteFile(filepath.Join(h.cwd, ".loupe.yaml"), []byte("project: my-board\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	_, err := h.run()
	if err == nil || !strings.Contains(err.Error(), "my-board") || len(h.opened) != 0 {
		t.Fatalf("err = %v, opened = %v", err, h.opened)
	}
}

func TestTheReviewCommandIsVisibleAndItsHelpersAreHidden(t *testing.T) {
	cmd := newReviewCmd()
	if cmd.Hidden {
		t.Fatal("review must be visible")
	}
	for _, sub := range cmd.Commands() {
		if want := sub.Name() != "ios"; sub.Hidden != want {
			t.Fatalf("%s hidden = %v, want %v", sub.Name(), sub.Hidden, want)
		}
	}
}
