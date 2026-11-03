package skills

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// put makes a version of a package in the store.
func put(t *testing.T, dir, pkg, version, meta string) string {
	t.Helper()
	p := filepath.Join(StoreDir(dir), pkg, version)
	if err := os.MkdirAll(p, 0o755); err != nil {
		t.Fatal(err)
	}
	if meta != "" {
		if err := os.WriteFile(filepath.Join(p, packageFile), []byte(meta), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	return p
}

func TestResolvePicksTheHighestVersionThatMeetsMinCli(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.2.0", `{"minCli":"1.0.0"}`)
	put(t, dir, "loupe", "1.10.0", `{"minCli":"1.0.0"}`)
	put(t, dir, "loupe", "2.0.0", `{"minCli":"9.0.0"}`)

	got, err := Resolve(dir, "1.35.0", []string{"loupe"})
	if err != nil || len(got) != 1 || got[0].Version != "1.10.0" {
		t.Fatalf("Resolve = %+v, %v, want 1.10.0", got, err)
	}
	if dev, err := Resolve(dir, "", []string{"loupe"}); err != nil || dev[0].Version != "2.0.0" {
		t.Fatalf("a development build meets every minCli: %+v, %v", dev, err)
	}
}

func TestResolveFailsForAPackageTheStoreLacks(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")

	if _, err := Resolve(dir, "1.0.0", []string{"loupe", "market-x"}); !errors.Is(err, ErrMissing) {
		t.Fatalf("err = %v, want ErrMissing", err)
	}
}

func TestResolveFailsWhenNoVersionMeetsMinCli(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", `{"minCli":"9.0.0"}`)

	if _, err := Resolve(dir, "1.0.0", []string{"loupe"}); !errors.Is(err, ErrMissing) {
		t.Fatalf("err = %v, want ErrMissing", err)
	}
}

func TestResolveChecksRequires(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "0.54.0", "")
	put(t, dir, "loupe-lifecycle", "1.0.0", `{"requires":{"loupe":">=0.55.0"}}`)

	if _, err := Resolve(dir, "1.0.0", []string{"loupe", "loupe-lifecycle"}); !errors.Is(err, ErrMissing) {
		t.Fatalf("err = %v, want ErrMissing for an old loupe", err)
	}
	put(t, dir, "loupe", "0.55.0", "")
	if got, err := Resolve(dir, "1.0.0", []string{"loupe", "loupe-lifecycle"}); err != nil || got[0].Version != "0.55.0" {
		t.Fatalf("Resolve = %+v, %v", got, err)
	}
}

func TestResolveTakesALowerVersionWhenAHigherNeedsWhatTheStoreLacks(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")
	put(t, dir, "loupe-lifecycle", "1.0.0", `{"requires":{"loupe":">=1.0.0"}}`)
	put(t, dir, "loupe-lifecycle", "2.0.0", `{"requires":{"loupe":">=2.0.0"}}`)

	for _, order := range [][]string{{"loupe", "loupe-lifecycle"}, {"loupe-lifecycle", "loupe"}} {
		got, err := Resolve(dir, "1.0.0", order)
		if err != nil || versionOf(got, "loupe") != "1.0.0" || versionOf(got, "loupe-lifecycle") != "1.0.0" {
			t.Fatalf("Resolve(%v) = %+v, %v, want both at 1.0.0", order, got, err)
		}
	}

	put(t, dir, "loupe", "2.0.0", "")
	got, err := Resolve(dir, "1.0.0", []string{"loupe", "loupe-lifecycle"})
	if err != nil || versionOf(got, "loupe") != "2.0.0" || versionOf(got, "loupe-lifecycle") != "2.0.0" {
		t.Fatalf("Resolve = %+v, %v, want both at 2.0.0", got, err)
	}
}

func TestResolveLowersAnEarlierPackageWhenALaterOneCapsIt(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")
	put(t, dir, "loupe", "2.0.0", "")
	put(t, dir, "loupe-lifecycle", "1.0.0", `{"requires":{"loupe":"<2.0.0"}}`)

	got, err := Resolve(dir, "1.0.0", []string{"loupe", "loupe-lifecycle"})
	if err != nil || versionOf(got, "loupe") != "1.0.0" {
		t.Fatalf("Resolve = %+v, %v, want loupe 1.0.0", got, err)
	}
}

func TestResolveSearchesADependencyOutsideTheList(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "base", "2.0.0", `{"minCli":"9.0.0"}`)
	put(t, dir, "base", "1.0.0", "")
	put(t, dir, "mid", "2.0.0", `{"requires":{"base":">=2.0.0"}}`)
	put(t, dir, "mid", "1.0.0", `{"requires":{"base":">=1.0.0"}}`)
	put(t, dir, "top", "2.0.0", `{"requires":{"mid":">=2.0.0"}}`)
	put(t, dir, "top", "1.0.0", `{"requires":{"mid":">=1.0.0"}}`)

	got, err := Resolve(dir, "1.0.0", []string{"top"})
	if err != nil || len(got) != 1 || got[0].Version != "1.0.0" {
		t.Fatalf("Resolve = %+v, %v, want top 1.0.0 alone", got, err)
	}
}

func TestResolveNamesTheRequirementWhenNoSetFits(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")
	put(t, dir, "loupe-lifecycle", "2.0.0", `{"requires":{"loupe":">=2.0.0"}}`)
	put(t, dir, "loupe-lifecycle", "1.0.0", `{"requires":{"loupe":">=1.5.0"}}`)

	_, err := Resolve(dir, "1.0.0", []string{"loupe", "loupe-lifecycle"})
	if !errors.Is(err, ErrMissing) || !strings.Contains(err.Error(), "loupe-lifecycle needs loupe >=2.0.0") {
		t.Fatalf("err = %v, want the unmet requirement", err)
	}
}

func TestCurrentNamesEachPickWithItsDependencies(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")
	put(t, dir, "loupe", "2.0.0", "")
	put(t, dir, "loupe-lifecycle", "1.0.0", `{"requires":{"loupe":"<2.0.0"}}`)

	got := Current(dir, "1.0.0", [][]string{{"loupe"}, {"loupe-lifecycle"}, {"market-x"}})
	if len(got) != 3 || !got["loupe/2.0.0"] || !got["loupe/1.0.0"] || !got["loupe-lifecycle/1.0.0"] {
		t.Fatalf("Current = %v", got)
	}
}

func versionOf(picks []Pick, pkg string) string {
	for _, p := range picks {
		if p.Package == pkg {
			return p.Version
		}
	}

	return ""
}

func TestExactNeedsEveryRecordedFolder(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")

	if got, ok := Exact(dir, map[string]string{"loupe": "1.0.0"}); !ok || got[0].Dir != filepath.Join(StoreDir(dir), "loupe", "1.0.0") {
		t.Fatalf("Exact = %+v, %v", got, ok)
	}
	if _, ok := Exact(dir, map[string]string{"loupe": "0.9.0"}); ok {
		t.Fatal("Exact took a version the store lacks")
	}
	if _, ok := Exact(dir, nil); ok {
		t.Fatal("Exact took an empty record")
	}
}

func TestARecordReadsBack(t *testing.T) {
	dir := t.TempDir()
	if err := WriteRecord(dir, "s1", []Pick{{Package: "loupe", Version: "1.0.0"}, {Package: "x", Version: "2.0.0"}}); err != nil {
		t.Fatal(err)
	}
	got := ReadRecord(dir, "s1")
	if got["loupe"] != "1.0.0" || got["x"] != "2.0.0" {
		t.Fatalf("record = %v", got)
	}
	if ReadRecord(dir, "s2") != nil {
		t.Fatal("a session with no record reads as nil")
	}
	if held := Held(dir); len(held) != 0 {
		t.Fatalf("the records folder reads as packages: %v", held)
	}
}

func TestASessionIDThatLeavesTheFolderHasNoRecord(t *testing.T) {
	dir := t.TempDir()
	for _, session := range []string{"", ".", "..", "../x", "a/b", ".hidden"} {
		if err := WriteRecord(dir, session, []Pick{{Package: "loupe", Version: "1.0.0"}}); err == nil {
			t.Errorf("WriteRecord(%q) wrote a record", session)
		}
		if ReadRecord(dir, session) != nil {
			t.Errorf("ReadRecord(%q) read a record", session)
		}
	}
}

func TestPruneDeletesAnOldRecord(t *testing.T) {
	dir := t.TempDir()
	for _, session := range []string{"old", "new"} {
		if err := WriteRecord(dir, session, []Pick{{Package: "loupe", Version: "1.0.0"}}); err != nil {
			t.Fatal(err)
		}
	}
	long := time.Now().Add(-KeepAfterReplace)
	if err := os.Chtimes(filepath.Join(StoreDir(dir), recordsDir, "old.json"), long, long); err != nil {
		t.Fatal(err)
	}

	Prune(dir, time.Now(), nil)
	if ReadRecord(dir, "old") != nil || ReadRecord(dir, "new") == nil {
		t.Fatalf("old = %v, new = %v", ReadRecord(dir, "old"), ReadRecord(dir, "new"))
	}
}

func TestPruneKeepsAVersionForThirtyDaysAfterItsReplacement(t *testing.T) {
	dir := t.TempDir()
	old := put(t, dir, "loupe", "1.0.0", "")
	mid := put(t, dir, "loupe", "1.1.0", "")
	newest := put(t, dir, "loupe", "1.2.0", "")
	now := time.Now()
	// 1.1.0 replaced 1.0.0 long ago, and 1.2.0 replaced 1.1.0 a day ago.
	for path, at := range map[string]time.Time{mid: now.Add(-40 * 24 * time.Hour), newest: now.Add(-24 * time.Hour)} {
		if err := os.Chtimes(path, at, at); err != nil {
			t.Fatal(err)
		}
	}

	removed := Prune(dir, now, nil)
	if len(removed) != 1 || removed[0] != "loupe/1.0.0" {
		t.Fatalf("removed = %v, want only 1.0.0", removed)
	}
	for _, kept := range []string{mid, newest} {
		if _, err := os.Stat(kept); err != nil {
			t.Fatalf("%s is gone: %v", kept, err)
		}
	}
	if _, err := os.Stat(old); err == nil {
		t.Fatal("1.0.0 is still there")
	}
}

func TestPruneKeepsAVersionALiveRunHolds(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")
	newest := put(t, dir, "loupe", "1.1.0", "")
	long := time.Now().Add(-90 * 24 * time.Hour)
	if err := os.Chtimes(newest, long, long); err != nil {
		t.Fatal(err)
	}

	if removed := Prune(dir, time.Now(), func(pkg, v string) bool { return pkg == "loupe" && v == "1.0.0" }); len(removed) != 0 {
		t.Fatalf("removed = %v, want nothing", removed)
	}
}

func TestSatisfies(t *testing.T) {
	for _, tc := range []struct {
		have, want string
		ok         bool
	}{
		{"0.55.0", ">=0.55.0", true},
		{"0.54.9", ">=0.55.0", false},
		{"1.5.0", ">=1.0.0 <2.0.0", true},
		{"2.0.0", ">=1.0.0,<2.0.0", false},
		{"1.0.0", "1.0.0", true},
		{"1.0.0", ">=garbage", false},
	} {
		if got := satisfies(tc.have, tc.want); got != tc.ok {
			t.Errorf("satisfies(%q, %q) = %v, want %v", tc.have, tc.want, got, tc.ok)
		}
	}
}

func TestHeldSkipsTheBackupFolderAndEmptyPackages(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "loupe", "1.0.0", "")
	put(t, dir, "backup", "2026-11-03", "")
	if err := os.MkdirAll(filepath.Join(StoreDir(dir), "empty"), 0o755); err != nil {
		t.Fatal(err)
	}

	if got := Held(dir); len(got) != 1 || got[0] != "loupe" {
		t.Fatalf("Held = %v, want loupe alone", got)
	}
}
