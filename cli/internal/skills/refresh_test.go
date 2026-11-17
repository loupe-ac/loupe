package skills

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/update"
	"github.com/loupe-ac/loupe/cli/internal/update/signtest"
)

// pkgFile is one file of a test archive.
type pkgFile struct{ name, body string }

// packArchive builds a package archive the way skills-release.yml does, with
// "./" before each name.
func packArchive(t *testing.T, files ...pkgFile) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for _, f := range files {
		if err := tw.WriteHeader(&tar.Header{Name: f.name, Mode: 0o644, Size: int64(len(f.body)), Typeflag: tar.TypeReg}); err != nil {
			t.Fatal(err)
		}
		if _, err := tw.Write([]byte(f.body)); err != nil {
			t.Fatal(err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}

	return buf.Bytes()
}

// goodArchive is a package at version with the given loupe-package.json.
func goodArchive(t *testing.T, version, meta string) []byte {
	return packArchive(t,
		pkgFile{"./.claude-plugin/plugin.json", `{"version":"` + version + `"}`},
		pkgFile{"./loupe-package.json", meta},
		pkgFile{"./skills/x/SKILL.md", "skill " + version},
	)
}

// fakeReleases serves package archives and checksums, and counts downloads.
type fakeReleases struct {
	server   *httptest.Server
	mu       sync.Mutex
	archives map[string][]byte
	sums     map[string][]byte
	releases []update.Release
	hits     int
	// key signs the checksums of each release, and is the only trusted key.
	key *signtest.Key
}

func newFakeReleases(t *testing.T) *fakeReleases {
	t.Helper()
	f := &fakeReleases{archives: map[string][]byte{}, sums: map[string][]byte{}, key: signtest.New(t)}
	f.key.Trust(t)
	f.server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.hits++
		if data, ok := f.archives[r.URL.Path]; ok {
			_, _ = w.Write(data)
		} else if data, ok := f.sums[r.URL.Path]; ok {
			_, _ = w.Write(data)
		} else {
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(f.server.Close)

	return f
}

// publish adds a release of pkg at version with the archive, and a correct
// checksum.
func (f *fakeReleases) publish(pkg, version string, archive []byte) *update.Release {
	name := pkg + "_" + version + ".tar.gz"
	sum := sha256.Sum256(archive)
	f.archives["/"+name] = archive
	sums := []byte(hex.EncodeToString(sum[:]) + "  " + name + "\n")
	f.sums["/sums/"+pkg+"/"+version] = sums
	f.sums["/sigs/"+pkg+"/"+version] = f.key.Sign(sums)
	f.releases = append(f.releases, update.Release{
		TagName: "skills/" + pkg + "/v" + version,
		Assets: []update.Asset{
			{Name: name, URL: f.server.URL + "/" + name},
			{Name: "checksums.txt", URL: f.server.URL + "/sums/" + pkg + "/" + version},
			{Name: "checksums.txt.sig", URL: f.server.URL + "/sigs/" + pkg + "/" + version},
		},
	})

	return &f.releases[len(f.releases)-1]
}

func (f *fakeReleases) downloads() int {
	f.mu.Lock()
	defer f.mu.Unlock()

	return f.hits
}

func refresh(t *testing.T, f *fakeReleases, dir, cli string, blocked map[string]string, pkgs ...string) []Result {
	t.Helper()
	if blocked == nil {
		blocked = map[string]string{}
	}

	return Refresh(context.Background(), f.server.Client(), dir, cli, f.releases, pkgs, blocked)
}

func storeEntries(t *testing.T, dir string) []string {
	t.Helper()
	var out []string
	_ = filepath.WalkDir(StoreDir(dir), func(p string, d os.DirEntry, err error) error {
		if err == nil && p != StoreDir(dir) {
			rel, _ := filepath.Rel(StoreDir(dir), p)
			if rel == failuresDir || strings.HasPrefix(rel, failuresDir+string(filepath.Separator)) {
				return nil
			}
			out = append(out, rel)
		}

		return nil
	})

	return out
}

func TestRefreshInstallsTheNewestRelease(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "loupe", "1.0.0", `{"minCli":"1.0.0"}`)
	f.publish("loupe", "1.0.0", goodArchive(t, "1.0.0", `{}`))
	f.publish("loupe", "1.2.0", goodArchive(t, "1.2.0", `{"minCli":"1.0.0"}`))
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`))

	got := refresh(t, f, dir, "1.5.0", nil, "loupe")

	want := Result{Package: "loupe", Version: "1.2.0", State: StateCurrent, Installed: "1.2.0"}
	if len(got) != 1 || got[0] != want {
		t.Fatalf("got %+v, want %+v", got, want)
	}
	data, err := os.ReadFile(filepath.Join(StoreDir(dir), "loupe", "1.2.0", "skills", "x", "SKILL.md"))
	if err != nil || string(data) != "skill 1.2.0" {
		t.Fatalf("installed file = %q, %v", data, err)
	}
	if _, err := os.Stat(filepath.Join(StoreDir(dir), "loupe", "1.1.0")); err == nil {
		t.Fatal("an older release was installed too")
	}
	if Status(dir, "1.5.0", "loupe").Version != "1.2.0" {
		t.Fatal("the store does not serve the new version")
	}
}

func TestRefreshInstallsAPackageTheStoreLacks(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe-lifecycle", "0.3.0", goodArchive(t, "0.3.0", `{}`))

	got := refresh(t, f, dir, "", nil, "loupe-lifecycle")

	if got[0].Installed != "0.3.0" || got[0].State != StateCurrent {
		t.Fatalf("got %+v", got)
	}
}

func TestRefreshSkipsAVersionTheStoreHolds(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "loupe", "1.2.0", "")
	f.publish("loupe", "1.2.0", goodArchive(t, "1.2.0", `{}`))
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`))

	got := refresh(t, f, dir, "1.0.0", nil, "loupe")

	if got[0].Version != "1.2.0" || got[0].State != StateCurrent || got[0].Installed != "" {
		t.Fatalf("got %+v", got)
	}
	if f.downloads() != 0 {
		t.Fatalf("%d downloads, want none", f.downloads())
	}
}

func TestRefreshRejectsABadChecksumAndLeavesTheStoreAlone(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "loupe", "1.0.0", "")
	before := storeEntries(t, dir)
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`))
	f.archives["/loupe_1.1.0.tar.gz"] = goodArchive(t, "1.1.0", `{"minCli":"0.0.1"}`)

	got := refresh(t, f, dir, "1.0.0", nil, "loupe")

	if got[0].State != StateFailed || !strings.Contains(got[0].Reason, "checksum") || got[0].Version != "1.0.0" {
		t.Fatalf("got %+v", got)
	}
	if after := storeEntries(t, dir); strings.Join(after, ",") != strings.Join(before, ",") {
		t.Fatalf("store changed: %v -> %v", before, after)
	}
}

func TestRefreshRejectsAnUnsafePathAndLeavesNoFolder(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe", "1.1.0", packArchive(t,
		pkgFile{"./.claude-plugin/plugin.json", `{"version":"1.1.0"}`},
		pkgFile{"../escape", "x"},
	))

	got := refresh(t, f, dir, "1.0.0", nil, "loupe")

	if got[0].State != StateFailed || !strings.Contains(got[0].Reason, "unsafe") {
		t.Fatalf("got %+v", got)
	}
	if entries := storeEntries(t, dir); len(entries) != 0 {
		t.Fatalf("store holds %v", entries)
	}
	if _, err := os.Stat(filepath.Join(dir, "escape")); err == nil {
		t.Fatal("a file left the store")
	}
}

func TestRefreshRejectsAnArchiveWhoseVersionIsNotItsTag(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe", "1.1.0", goodArchive(t, "1.0.9", `{}`))

	got := refresh(t, f, dir, "1.0.0", nil, "loupe")

	if got[0].State != StateFailed || !strings.Contains(got[0].Reason, "1.0.9") || len(storeEntries(t, dir)) != 0 {
		t.Fatalf("got %+v, store %v", got, storeEntries(t, dir))
	}
}

func TestRefreshLeavesOutAVersionThatNeedsANewerCLI(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe", "1.0.0", goodArchive(t, "1.0.0", `{"minCli":"1.0.0"}`))
	f.publish("loupe", "2.0.0", goodArchive(t, "2.0.0", `{"minCli":"9.0.0"}`))
	blocked := map[string]string{}

	got := refresh(t, f, dir, "1.5.0", blocked, "loupe")

	if got[0].Version != "1.0.0" || got[0].Installed != "1.0.0" || got[0].State != StateFailed || !strings.Contains(got[0].Reason, "needs CLI 9.0.0") {
		t.Fatalf("got %+v", got)
	}
	if _, err := os.Stat(filepath.Join(StoreDir(dir), "loupe", "2.0.0")); err == nil {
		t.Fatal("the version above the CLI was installed")
	}
	if blocked["loupe/2.0.0"] == "" {
		t.Fatalf("blocked = %v", blocked)
	}

	hits := f.downloads()
	again := refresh(t, f, dir, "1.5.0", blocked, "loupe")
	if f.downloads() != hits || again[0].State != StateFailed {
		t.Fatalf("the blocked version downloaded again (%d -> %d): %+v", hits, f.downloads(), again)
	}
}

func TestADevelopmentBuildMeetsEveryMinCli(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe", "2.0.0", goodArchive(t, "2.0.0", `{"minCli":"9.0.0"}`))

	got := refresh(t, f, dir, "", nil, "loupe")

	if got[0].Version != "2.0.0" || got[0].State != StateCurrent {
		t.Fatalf("got %+v", got)
	}
}

func TestRefreshLeavesOutAVersionWhoseRequiresTheStoreCannotMeet(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "loupe", "0.5.0", "")
	put(t, dir, "loupe-lifecycle", "0.1.0", "")
	f.publish("loupe-lifecycle", "0.2.0", goodArchive(t, "0.2.0", `{"requires":{"loupe":">=0.55.0"}}`))

	got := refresh(t, f, dir, "1.0.0", nil, "loupe-lifecycle")

	if got[0].Version != "0.1.0" || got[0].State != StateFailed || !strings.Contains(got[0].Reason, "needs loupe >=0.55.0") {
		t.Fatalf("got %+v", got)
	}
	if _, err := os.Stat(filepath.Join(StoreDir(dir), "loupe-lifecycle", "0.2.0")); err == nil {
		t.Fatal("the version was installed")
	}
}

func TestRefreshLeavesOutAVersionWhoseDependencyNeedsAMissingPackage(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "app", "1.0.0", "")
	put(t, dir, "middle", "1.0.0", `{"requires":{"absent":">=1.0.0 <2.0.0"}}`)
	f.publish("app", "2.0.0", goodArchive(t, "2.0.0", `{"requires":{"middle":">=1.0.0 <2.0.0"}}`))

	got := refresh(t, f, dir, "1.0.0", nil, "app")

	if got[0].Version != "1.0.0" || got[0].State != StateFailed || !strings.Contains(got[0].Reason, "absent") {
		t.Fatalf("got %+v", got)
	}
	if _, err := os.Stat(filepath.Join(StoreDir(dir), "app", "2.0.0")); err == nil {
		t.Fatal("the version was installed")
	}
}

// The requires of two packages conflict, though each has a fitting version alone.
func TestRefreshLeavesOutAVersionWhoseDependenciesConflict(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "app", "1.0.0", "")
	put(t, dir, "left", "1.0.0", `{"requires":{"base":">=1.0.0 <2.0.0"}}`)
	put(t, dir, "right", "1.0.0", `{"requires":{"base":">=2.0.0 <3.0.0"}}`)
	put(t, dir, "base", "1.0.0", "")
	put(t, dir, "base", "2.0.0", "")
	f.publish("app", "2.0.0", goodArchive(t, "2.0.0", `{"requires":{"left":">=1.0.0 <2.0.0","right":">=1.0.0 <2.0.0"}}`))

	got := refresh(t, f, dir, "1.0.0", nil, "app")

	if got[0].Version != "1.0.0" || got[0].State != StateFailed || !strings.Contains(got[0].Reason, "base") {
		t.Fatalf("got %+v", got)
	}
}

func TestRefreshInstallsAVersionWhoseWholeDependencySetFits(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "app", "1.0.0", "")
	put(t, dir, "middle", "1.0.0", `{"requires":{"base":">=1.0.0 <2.0.0"}}`)
	put(t, dir, "base", "1.0.0", "")
	f.publish("app", "2.0.0", goodArchive(t, "2.0.0", `{"requires":{"middle":">=1.0.0 <2.0.0"}}`))

	got := refresh(t, f, dir, "1.0.0", nil, "app")

	if got[0].Version != "2.0.0" || got[0].State != StateCurrent || got[0].Installed != "2.0.0" {
		t.Fatalf("got %+v", got)
	}
}

// The store can hold such a version from another CLI, and a later check skips a
// version the store holds, so only this reason says why the package stays behind.
func TestRefreshFailsWithTheReasonOfAStoredVersionThatCannotStart(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "loupe", "1.0.0", "")
	put(t, dir, "loupe", "2.0.0", `{"requires":{"absent":"^1.0.0"}}`)
	f.publish("loupe", "2.0.0", goodArchive(t, "2.0.0", `{"requires":{"absent":"^1.0.0"}}`))

	got := refresh(t, f, dir, "1.0.0", nil, "loupe")

	if got[0].Version != "1.0.0" || got[0].State != StateFailed || !strings.Contains(got[0].Reason, "loupe 2.0.0") || !strings.Contains(got[0].Reason, "absent") {
		t.Fatalf("got %+v", got)
	}
	if f.downloads() != 0 {
		t.Fatalf("%d downloads, want none", f.downloads())
	}
}

func TestRefreshNamesAStoredVersionThatNeedsANewerCLI(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")
	put(t, dir, "loupe", "2.0.0", `{"minCli":"9.0.0"}`)

	got := Refresh(context.Background(), http.DefaultClient, dir, "1.0.0", nil, []string{"loupe"}, map[string]string{})

	if got[0].Version != "1.0.0" || got[0].State != StateFailed || !strings.Contains(got[0].Reason, "needs CLI 9.0.0") {
		t.Fatalf("got %+v", got)
	}
}

func TestReportKeepsTheFailureOfARefreshInAnotherProcess(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "loupe", "1.0.0", "")
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{"minCli":"9.0.0"}`))

	failed := refresh(t, f, dir, "1.0.0", nil, "loupe")[0]

	if got := Report(dir, "1.0.0", "loupe"); got.State != StateFailed || got.Reason != failed.Reason || got.Version != "1.0.0" {
		t.Fatalf("report = %+v", got)
	}
	if got := Status(dir, "1.0.0", "loupe"); got.State != StateCurrent {
		t.Fatalf("status = %+v, want the store alone", got)
	}
}

func TestReportEndsTheFailureWhenARefreshSucceeds(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "loupe", "1.0.0", "")
	RecordResults(dir, []Result{{Package: "loupe", Version: "1.0.0", State: StateFailed, Reason: "bad"}})

	refresh(t, f, dir, "1.0.0", nil, "loupe")

	if got := Report(dir, "1.0.0", "loupe"); got.State != StateCurrent || got.Reason != "" {
		t.Fatalf("report = %+v", got)
	}
}

func TestReportEndsTheFailureWhenTheStoreHoldsAnotherVersion(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")
	RecordResults(dir, []Result{{Package: "loupe", Version: "1.0.0", State: StateFailed, Reason: "bad"}})

	put(t, dir, "loupe", "1.1.0", "")

	if got := Report(dir, "1.0.0", "loupe"); got.State != StateCurrent || got.Version != "1.1.0" {
		t.Fatalf("report = %+v", got)
	}
}

func TestACancelledRefreshKeepsTheLastFailure(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "loupe", "1.0.0", "")
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`))
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	Refresh(ctx, f.server.Client(), dir, "1.0.0", f.releases, []string{"loupe"}, map[string]string{})

	if got := Report(dir, "1.0.0", "loupe"); got.State != StateCurrent {
		t.Fatalf("report = %+v", got)
	}
	if _, err := os.Stat(filepath.Join(StoreDir(dir), failuresDir)); err == nil {
		t.Fatal("a cancelled refresh recorded a failure")
	}
}

func TestAnInvalidPackageNameWritesNoFailureFile(t *testing.T) {
	dir := t.TempDir()

	RecordResults(dir, []Result{{Package: "../../escape", State: StateFailed, Reason: "bad"}})

	if _, err := os.Stat(filepath.Join(StoreDir(dir), failuresDir)); err == nil {
		t.Fatal("the store holds a failure for an invalid name")
	}
	if got := Report(dir, "1.0.0", "../../escape"); got.Reason == "bad" {
		t.Fatalf("report = %+v", got)
	}
}

func TestRefreshMeetsARequiresWithAPackageItInstallsInTheSameRun(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "loupe", "0.5.0", "")
	put(t, dir, "loupe-lifecycle", "0.1.0", "")
	f.publish("loupe", "0.60.0", goodArchive(t, "0.60.0", `{}`))
	f.publish("loupe-lifecycle", "0.2.0", goodArchive(t, "0.2.0", `{"requires":{"loupe":">=0.55.0"}}`))

	// The package that needs the other comes first in the list.
	got := refresh(t, f, dir, "1.0.0", nil, "loupe-lifecycle", "loupe")

	for _, r := range got {
		if r.State != StateCurrent || r.Installed == "" {
			t.Fatalf("got %+v", got)
		}
	}
}

func TestRefreshFailsForAPackageWithNoRelease(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "held", "1.0.0", "")

	got := refresh(t, f, dir, "1.0.0", nil, "held", "unknown", "Bad Name")

	if got[1].State != StateCurrent || got[1].Version != "1.0.0" {
		t.Fatalf("a held package with no release: %+v", got[1])
	}
	if got[0].Package != "Bad Name" || got[0].State != StateFailed || !strings.Contains(got[0].Reason, "not a valid package name") {
		t.Fatalf("got %+v", got[0])
	}
	if got[2].Package != "unknown" || got[2].State != StateFailed || !strings.Contains(got[2].Reason, "no release") {
		t.Fatalf("got %+v", got[2])
	}
	if len(storeEntries(t, dir)) != 2 {
		t.Fatalf("store = %v", storeEntries(t, dir))
	}
}

func TestRefreshIgnoresDraftsPrereleasesAndOtherTags(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`)).Draft = true
	f.publish("loupe", "1.2.0", goodArchive(t, "1.2.0", `{}`)).Prerelease = true
	f.publish("loupe", "1.3.0", goodArchive(t, "1.3.0", `{}`)).TagName = "cli/v1.3.0"
	f.publish("loupe", "1.4.0", goodArchive(t, "1.4.0", `{}`)).TagName = "skills/loupe-lifecycle/v1.4.0"
	f.publish("loupe", "1.5.0", goodArchive(t, "1.5.0", `{}`)).Assets = nil
	f.publish("loupe", "1.0.0", goodArchive(t, "1.0.0", `{}`))

	got := refresh(t, f, dir, "", nil, "loupe")

	if got[0].Version != "1.0.0" {
		t.Fatalf("got %+v", got)
	}
}

func TestStatusReportsOnlyAVersionWhoseRequiresTheStoreMeets(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")
	put(t, dir, "loupe", "2.0.0", `{"requires":{"absent":"^1.0.0"}}`)
	put(t, dir, "stuck", "1.0.0", `{"requires":{"loupe":"^3.0.0"}}`)

	if got := Status(dir, "1.0.0", "loupe"); got.Version != "1.0.0" || got.State != StateCurrent {
		t.Fatalf("loupe = %+v", got)
	}
	got := Status(dir, "1.0.0", "stuck")
	if got.State != StateFailed || got.Version != "" || !strings.Contains(got.Reason, "loupe") {
		t.Fatalf("stuck = %+v", got)
	}
}

// A store version with an unmet requires gives the refresh no version to report
// as current, also when no release can replace it.
func TestRefreshFailsAPackageWhoseStoredVersionsCannotStart(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "stuck", "1.0.0", `{"requires":{"absent":"^1.0.0"}}`)

	got := Refresh(context.Background(), http.DefaultClient, dir, "1.0.0", nil, []string{"stuck"}, map[string]string{})

	if got[0].State != StateFailed || got[0].Version != "" || !strings.Contains(got[0].Reason, "absent") {
		t.Fatalf("got %+v", got[0])
	}
}

func TestStatusNamesAPackageWithNoUsableVersion(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", `{"minCli":"9.0.0"}`)

	got := Status(dir, "1.0.0", "loupe")

	if got.State != StateFailed || got.Version != "" || got.Reason == "" {
		t.Fatalf("got %+v", got)
	}
}

func TestRefreshInstallsNothingFromAReleaseWithABadSignature(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	put(t, dir, "loupe", "1.0.0", "")
	f.publish("loupe", "1.2.0", goodArchive(t, "1.2.0", `{}`))
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`))
	f.sums["/sigs/loupe/1.2.0"] = signtest.New(t).Sign(f.sums["/sums/loupe/1.2.0"])

	got := refresh(t, f, dir, "1.0.0", nil, "loupe")

	if got[0].Installed != "1.1.0" || got[0].Version != "1.1.0" {
		t.Fatalf("got %+v, want the lower version 1.1.0", got[0])
	}
	if got[0].State != StateFailed || !strings.Contains(got[0].Reason, "signature") || !strings.Contains(got[0].Reason, "1.2.0") {
		t.Fatalf("the result does not name the signature of 1.2.0: %+v", got[0])
	}
	if _, err := os.Stat(filepath.Join(StoreDir(dir), "loupe", "1.2.0")); err == nil {
		t.Fatal("the version with a bad signature is in the store")
	}
}

func TestRefreshChecksTheSignatureBeforeItDownloadsTheArchive(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`))
	f.sums["/sigs/loupe/1.1.0"] = []byte("garbage")
	archiveHits := 0
	inner := f.server.Config.Handler
	f.server.Config.Handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, ".tar.gz") {
			archiveHits++
		}
		inner.ServeHTTP(w, r)
	})

	got := refresh(t, f, dir, "1.0.0", nil, "loupe")

	if got[0].State != StateFailed || archiveHits != 0 {
		t.Fatalf("got %+v, archive downloads %d", got[0], archiveHits)
	}
}

func TestRefreshRetriesAVersionWithABadSignatureOnTheNextRun(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`))
	good := f.sums["/sigs/loupe/1.1.0"]
	f.sums["/sigs/loupe/1.1.0"] = []byte("garbage")
	blocked := map[string]string{}
	if got := refresh(t, f, dir, "1.0.0", blocked, "loupe"); got[0].State != StateFailed {
		t.Fatalf("got %+v", got[0])
	}
	if len(blocked) != 0 {
		t.Fatalf("a bad signature blocked the version for the process: %v", blocked)
	}
	f.sums["/sigs/loupe/1.1.0"] = good
	if got := refresh(t, f, dir, "1.0.0", blocked, "loupe"); got[0].Installed != "1.1.0" {
		t.Fatalf("got %+v", got[0])
	}
}

func TestRefreshIgnoresAReleaseWithNoSignatureAsset(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`))
	f.publish("loupe", "1.2.0", goodArchive(t, "1.2.0", `{}`))
	last := &f.releases[len(f.releases)-1]
	last.Assets = last.Assets[:2]

	got := refresh(t, f, dir, "1.0.0", nil, "loupe")

	if got[0].Installed != "1.1.0" || got[0].State != StateCurrent {
		t.Fatalf("got %+v", got[0])
	}
}

func TestRefreshWritesTheSignerNextToTheVersion(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`))

	refresh(t, f, dir, "1.0.0", nil, "loupe")

	data, err := os.ReadFile(filepath.Join(StoreDir(dir), "loupe", "1.1.0", ".signer"))
	if err != nil || strings.TrimSpace(string(data)) != f.key.Fingerprint {
		t.Fatalf(".signer = %q, %v, want %s", data, err, f.key.Fingerprint)
	}
}

func TestRefreshRefusesARevokedKey(t *testing.T) {
	dir, f := t.TempDir(), newFakeReleases(t)
	f.publish("loupe", "1.1.0", goodArchive(t, "1.1.0", `{}`))
	if _, err := update.AddRevoked(dir, []string{f.key.Fingerprint}); err != nil {
		t.Fatal(err)
	}

	got := refresh(t, f, dir, "1.0.0", nil, "loupe")

	if got[0].State != StateFailed || got[0].Installed != "" {
		t.Fatalf("got %+v", got[0])
	}
}

// storeSigned puts a version in the store as the refresh does, with its signer.
func storeSigned(t *testing.T, dir, pkg, version, signer string) {
	t.Helper()
	put(t, dir, pkg, version, "")
	if err := os.WriteFile(filepath.Join(StoreDir(dir), pkg, version, ".signer"), []byte(signer+"\n"), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestAStoredVersionFromARevokedKeyIsNeverPickedAndTheRefreshDeletesIt(t *testing.T) {
	dir := t.TempDir()
	storeSigned(t, dir, "loupe", "1.0.0", "SHA256:good")
	storeSigned(t, dir, "loupe", "2.99.0", "SHA256:stolen")
	if _, err := update.AddRevoked(dir, []string{"SHA256:stolen"}); err != nil {
		t.Fatal(err)
	}

	picks, err := Resolve(dir, "1.5.0", []string{"loupe"})
	if err != nil || picks[0].Version != "1.0.0" {
		t.Fatalf("Resolve = %+v, %v, want the highest version left, 1.0.0", picks, err)
	}
	if cur := Current(dir, "1.5.0", [][]string{{"loupe"}}); cur["loupe/2.99.0"] || !cur["loupe/1.0.0"] {
		t.Fatalf("Current = %v", cur)
	}
	if _, err := os.Stat(filepath.Join(StoreDir(dir), "loupe", "2.99.0")); err != nil {
		t.Fatal("the folder is gone before a refresh")
	}

	f := newFakeReleases(t)
	f.publish("loupe", "1.0.0", goodArchive(t, "1.0.0", `{}`))
	refresh(t, f, dir, "1.5.0", nil, "loupe")

	if _, err := os.Stat(filepath.Join(StoreDir(dir), "loupe", "2.99.0")); err == nil {
		t.Fatal("the refresh kept the version of the revoked key")
	}
	if _, err := os.Stat(filepath.Join(StoreDir(dir), "loupe", "1.0.0")); err != nil {
		t.Fatal("the refresh deleted the good version")
	}
}

func TestACodexPickSkipsAVersionFromARevokedKey(t *testing.T) {
	dir := t.TempDir()
	storeSigned(t, dir, "loupe", "1.0.0", "SHA256:good")
	storeSigned(t, dir, "loupe", "2.99.0", "SHA256:stolen")
	if _, err := update.AddRevoked(dir, []string{"SHA256:stolen"}); err != nil {
		t.Fatal(err)
	}
	for _, p := range CodexPicks(dir, "1.5.0") {
		if p.Version == "2.99.0" {
			t.Fatalf("CodexPicks chose %+v", p)
		}
	}
}

func TestExactRefusesARecordedVersionFromARevokedKey(t *testing.T) {
	dir := t.TempDir()
	storeSigned(t, dir, "loupe", "1.0.0", "SHA256:good")
	storeSigned(t, dir, "loupe", "2.99.0", "SHA256:stolen")
	recorded := map[string]string{"loupe": "2.99.0"}
	if _, ok := Exact(dir, recorded); !ok {
		t.Fatal("Exact refused a version before its key was revoked")
	}
	if _, err := update.AddRevoked(dir, []string{"SHA256:stolen"}); err != nil {
		t.Fatal(err)
	}

	if _, ok := Exact(dir, recorded); ok {
		t.Fatal("Exact returned a version from a revoked key, before the refresh deleted it")
	}
	if picks, ok := Exact(dir, map[string]string{"loupe": "1.0.0"}); !ok || len(picks) != 1 {
		t.Fatalf("Exact refused a good version: %+v", picks)
	}
}

func TestAVersionWithNoSignerFileIsNotRevoked(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")
	if _, err := update.AddRevoked(dir, []string{"SHA256:stolen"}); err != nil {
		t.Fatal(err)
	}
	if picks, err := Resolve(dir, "1.5.0", []string{"loupe"}); err != nil || picks[0].Version != "1.0.0" {
		t.Fatalf("Resolve = %+v, %v", picks, err)
	}
}
