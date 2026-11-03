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
}

func newFakeReleases(t *testing.T) *fakeReleases {
	t.Helper()
	f := &fakeReleases{archives: map[string][]byte{}, sums: map[string][]byte{}}
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
	f.sums["/sums/"+pkg+"/"+version] = []byte(hex.EncodeToString(sum[:]) + "  " + name + "\n")
	f.releases = append(f.releases, update.Release{
		TagName: "skills/" + pkg + "/v" + version,
		Assets: []update.Asset{
			{Name: name, URL: f.server.URL + "/" + name},
			{Name: "checksums.txt", URL: f.server.URL + "/sums/" + pkg + "/" + version},
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

func TestStatusNamesAPackageWithNoUsableVersion(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", `{"minCli":"9.0.0"}`)

	got := Status(dir, "1.0.0", "loupe")

	if got.State != StateFailed || got.Version != "" || got.Reason == "" {
		t.Fatalf("got %+v", got)
	}
}
