package cmd

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/update"
)

// panelArchive is an archive of the binary and a small Live review app.
func panelArchive(t *testing.T, binary string) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	add := func(name string, typ byte, mode int64, body string) {
		hdr := &tar.Header{Name: name, Typeflag: typ, Mode: mode, Size: int64(len(body))}
		if err := tw.WriteHeader(hdr); err != nil {
			t.Fatal(err)
		}
		if _, err := tw.Write([]byte(body)); err != nil {
			t.Fatal(err)
		}
	}
	add("loupe", tar.TypeReg, 0o755, binary)
	add("Live review.app/", tar.TypeDir, 0o755, "")
	add("Live review.app/Contents/MacOS/panel", tar.TypeReg, 0o755, "panel")
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}

	return buf.Bytes()
}

func panelPath(dir, version string) string {
	return filepath.Join(update.PanelDir(dir, version), update.PanelAppName, "Contents", "MacOS", "panel")
}

func darwinUpdater(t *testing.T, gh *fakeGitHub, running string) *updaterHarness {
	t.Helper()
	gh.goos = "darwin"
	h := newTestUpdater(t, gh, running)
	h.u.goos = "darwin"
	h.u.setRange("^1.0")

	return h
}

func TestTheUpdaterStagesThePanelOnMacOS(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	h := darwinUpdater(t, gh, "1.0.0")

	h.u.check(context.Background())

	if data, err := os.ReadFile(panelPath(h.u.dir, "1.2.0")); err != nil || string(data) != "panel" {
		t.Fatalf("panel = %q, %v", data, err)
	}
	if len(h.staged) != 1 {
		t.Fatalf("hook calls = %v", h.staged)
	}
}

func TestTheUpdaterStagesNoPanelOnLinux(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if _, err := os.Stat(update.PanelDir(h.u.dir, "1.2.0")); err == nil {
		t.Fatal("a Linux updater must not unpack a panel")
	}
}

func TestTheUpdaterRejectsAnArchiveWithAnUnsafePanel(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for _, hdr := range []*tar.Header{
		{Name: "loupe", Typeflag: tar.TypeReg, Mode: 0o755, Size: 1},
		{Name: "Live review.app/l", Typeflag: tar.TypeSymlink, Linkname: "/etc"},
	} {
		if err := tw.WriteHeader(hdr); err != nil {
			t.Fatal(err)
		}
		if hdr.Size > 0 {
			_, _ = tw.Write([]byte("x"))
		}
	}
	_ = tw.Close()
	_ = gz.Close()
	gh.setArchive(buf.Bytes())
	h := darwinUpdater(t, gh, "1.0.0")

	res := h.u.run(context.Background(), false)

	if res.Outcome != outcomeRejected || len(h.staged) != 0 {
		t.Fatalf("result = %+v, staged = %v", res, h.staged)
	}
	if _, err := os.Stat(update.StagePath(h.u.dir, "1.2.0")); err == nil {
		t.Fatal("the binary of a rejected archive was staged")
	}
}

func TestTheUpdaterDoesNotDownloadAgainWhenTheArchiveHoldsNoPanel(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := darwinUpdater(t, gh, "1.0.0")

	h.u.check(context.Background())
	h.u.check(context.Background())

	if _, downloads := gh.counts(); downloads != 2 {
		t.Fatalf("downloads = %d, want the archive and the checksums once", downloads)
	}
}

func TestTheUpdaterFetchesAgainWhenAStagedBinaryHasNoPanel(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	h := darwinUpdater(t, gh, "1.0.0")
	path := update.StagePath(h.u.dir, "1.2.0")
	if err := update.WriteFile(path, []byte("new binary"), 0o755); err != nil {
		t.Fatal(err)
	}
	st := &update.State{Staged: map[string]string{"1.2.0": path}}
	if err := st.Save(h.u.dir); err != nil {
		t.Fatal(err)
	}

	h.u.check(context.Background())

	if _, err := os.Stat(panelPath(h.u.dir, "1.2.0")); err != nil {
		t.Fatalf("the panel is missing: %v", err)
	}
}

func TestPruneVersionsRemovesThePanelsOfOtherVersions(t *testing.T) {
	dir := t.TempDir()
	for _, v := range []string{"0.9.0", "1.0.0", "1.2.0"} {
		if _, err := update.ExtractPanel(panelArchive(t, "x"), update.PanelDir(dir, v)); err != nil {
			t.Fatal(err)
		}
	}

	if err := pruneVersions(dir, "v1.2.0", "1.0.0"); err != nil {
		t.Fatal(err)
	}

	for v, want := range map[string]bool{"0.9.0": false, "1.0.0": true, "1.2.0": true} {
		_, err := os.Stat(update.PanelDir(dir, v))
		if (err == nil) != want {
			t.Fatalf("panel %s present = %v, want %v", v, err == nil, want)
		}
	}
	if _, err := os.Stat(filepath.Join(dir, "update.json")); err == nil {
		t.Fatal("pruning panels alone must not write update.json")
	}
}

func darwinSelfUpdate(t *testing.T, gh *fakeGitHub, running string) selfUpdate {
	t.Helper()
	shortConfigHome(t)
	gh.goos = "darwin"
	self, _ := selfUpdateAgainst(t, gh, running)
	self.goos = "darwin"

	return withServerRange(self, "^1.0")
}

func TestUpdateInstallsTheMissingPanelOfACurrentBinary(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	self := darwinSelfUpdate(t, gh, "1.2.0")

	out, err := updateCmd(t, self)

	if err != nil || !strings.Contains(out, "current at 1.2.0") || !strings.Contains(out, "installed the Live review panel for 1.2.0") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if _, err := os.Stat(panelPath(mustConfigDir(t), "1.2.0")); err != nil {
		t.Fatalf("the panel is missing: %v", err)
	}
}

func TestUpdateLeavesAPanelThatIsThere(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	self := darwinSelfUpdate(t, gh, "1.2.0")
	if _, err := update.ExtractPanel(panelArchive(t, "x"), update.PanelDir(mustConfigDir(t), "1.2.0")); err != nil {
		t.Fatal(err)
	}

	out, err := updateCmd(t, self)

	if err != nil || strings.Contains(out, "panel") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if _, downloads := gh.counts(); downloads != 0 {
		t.Fatalf("downloads = %d", downloads)
	}
}

func TestUpdateLeavesAPanelInLibexec(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	self := darwinSelfUpdate(t, gh, "1.2.0")
	exe, _ := self.executable()
	if err := os.MkdirAll(filepath.Join(filepath.Dir(exe), "..", "libexec", update.PanelAppName), 0o755); err != nil {
		t.Fatal(err)
	}

	out, err := updateCmd(t, self)

	if err != nil || strings.Contains(out, "panel") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestUpdateWarnsAndExitsCleanlyWhenThePanelCannotBeFetched(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	gh.checksums = []byte(strings.Repeat("0", 64) + "  " + gh.assetName() + "\n")
	self := darwinSelfUpdate(t, gh, "1.2.0")

	out, err := updateCmd(t, self)

	if err != nil || !strings.Contains(out, "warning: could not fetch the Live review panel") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if _, err := os.Stat(update.PanelDir(mustConfigDir(t), "1.2.0")); err == nil {
		t.Fatal("a failed fetch must install nothing")
	}
}

func TestUpdateSkipsThePanelOffMacOS(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	self := darwinSelfUpdate(t, gh, "1.2.0")
	self.goos = "linux"
	gh.goos = "linux"

	out, err := updateCmd(t, self)

	if err != nil || strings.Contains(out, "panel") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestUpdateInstallsThePanelOfANewBinaryThroughStage(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	self := darwinSelfUpdate(t, gh, "1.0.0")

	out, err := updateCmd(t, self)

	if err != nil || !strings.Contains(out, "installed 1.2.0") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if _, err := os.Stat(panelPath(mustConfigDir(t), "1.2.0")); err != nil {
		t.Fatalf("the panel is missing: %v", err)
	}
}

func TestTheUpdaterInstallsTheMissingPanelOfACurrentBinary(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
	gh.setArchive(panelArchive(t, "binary"))
	h := darwinUpdater(t, gh, "1.0.0")

	h.u.check(context.Background())

	if data, err := os.ReadFile(panelPath(h.u.dir, "1.0.0")); err != nil || string(data) != "panel" {
		t.Fatalf("panel = %q, %v", data, err)
	}
	if h.u.state().State != updateCurrent {
		t.Fatalf("state = %+v", h.u.state())
	}
}

func TestTheUpdaterInstallsTheMissingPanelWhenAnUpdateWaitsForTheOwner(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "binary"))
	h := darwinUpdater(t, gh, "1.0.0")
	h.auto = false

	h.u.check(context.Background())

	if _, err := os.Stat(panelPath(h.u.dir, "1.0.0")); err != nil {
		t.Fatalf("the panel is missing: %v", err)
	}
	if h.u.state().State != updateOff {
		t.Fatalf("state = %+v", h.u.state())
	}
}

func TestAFailedPanelFetchLogsAndKeepsTheCheckCurrent(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
	gh.setArchive(panelArchive(t, "binary"))
	gh.checksums = []byte(strings.Repeat("0", 64) + "  " + gh.assetName() + "\n")
	h := darwinUpdater(t, gh, "1.0.0")

	res := h.u.run(context.Background(), false)

	if !res.OK || res.Outcome != updateCurrent || h.events("update_panel_failed") != 1 {
		t.Fatalf("result = %+v, log = %s", res, h.log.String())
	}
}

func TestAPanelThatCannotBeWrittenDoesNotBlockTheBinary(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	h := darwinUpdater(t, gh, "1.0.0")
	if err := os.WriteFile(filepath.Join(h.u.dir, "live-review"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}

	h.u.check(context.Background())

	if len(h.staged) != 1 {
		t.Fatalf("hook calls = %v, log = %s", h.staged, h.log.String())
	}
	if data, err := os.ReadFile(update.StagePath(h.u.dir, "1.2.0")); err != nil || string(data) != "new binary" {
		t.Fatalf("staged binary = %q, %v", data, err)
	}
	if h.events("update_stage_failed") != 1 {
		t.Fatalf("log = %s", h.log.String())
	}
}

func TestAFailedPanelLeavesNoMarkerSoALaterCheckRetries(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	h := darwinUpdater(t, gh, "1.0.0")
	blocker := filepath.Join(h.u.dir, "live-review")
	if err := os.WriteFile(blocker, []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}
	h.u.check(context.Background())
	if err := os.Remove(blocker); err != nil {
		t.Fatal(err)
	}

	h.u.check(context.Background())

	if _, err := os.Stat(panelPath(h.u.dir, "1.2.0")); err != nil {
		t.Fatalf("the second check did not retry: %v", err)
	}
}

func TestUpdatePrunesOldVersionsAfterTheSwap(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0", "cli/v1.2.0")
	gh.setArchive(panelArchive(t, "new binary"))
	self := darwinSelfUpdate(t, gh, "1.0.0")
	dir := mustConfigDir(t)
	if _, err := update.ExtractPanel(panelArchive(t, "x"), update.PanelDir(dir, "0.9.0")); err != nil {
		t.Fatal(err)
	}
	versionDir(t, dir, "0.9.0")

	if _, err := updateCmd(t, self); err != nil {
		t.Fatal(err)
	}

	for _, gone := range []string{update.PanelDir(dir, "0.9.0"), filepath.Join(dir, "versions", "0.9.0")} {
		if _, err := os.Stat(gone); err == nil {
			t.Fatalf("%s must be gone", gone)
		}
	}
	if _, err := os.Stat(panelPath(dir, "1.2.0")); err != nil {
		t.Fatalf("the new panel must stay: %v", err)
	}
	if _, err := os.Stat(update.StagePath(dir, "1.2.0")); err != nil {
		t.Fatalf("the new version must stay: %v", err)
	}
}
