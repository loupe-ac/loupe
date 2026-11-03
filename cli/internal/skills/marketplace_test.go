package skills

import (
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"testing"
	"time"
)

// putCodex makes a package version with a Codex manifest and the named skills.
func putCodex(t *testing.T, dir, pkg, version string, skills ...string) string {
	t.Helper()
	p := put(t, dir, pkg, version, "")
	if err := os.MkdirAll(filepath.Join(p, ".codex-plugin"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(p, codexManifest), []byte(`{"name":"`+pkg+`","version":"`+version+`"}`), 0o644); err != nil {
		t.Fatal(err)
	}
	for _, s := range skills {
		if err := os.MkdirAll(filepath.Join(p, "skills", s), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	return p
}

func readMarketplace(t *testing.T, dir string) marketplaceFile {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(MarketplaceDir(dir), ".agents", "plugins", "marketplace.json"))
	if err != nil {
		t.Fatal(err)
	}
	var file marketplaceFile
	if err := json.Unmarshal(data, &file); err != nil {
		t.Fatal(err)
	}

	return file
}

func TestBuildMarketplaceLinksTheCurrentVersionOfEachPackage(t *testing.T) {
	dir := t.TempDir()
	putCodex(t, dir, "loupe", "1.0.0")
	current := putCodex(t, dir, "loupe", "1.1.0")
	putCodex(t, dir, "loupe-lifecycle", "0.3.0")

	picks, err := BuildMarketplace(dir, "")
	if err != nil || len(picks) != 2 {
		t.Fatalf("BuildMarketplace = %+v, %v", picks, err)
	}
	file := readMarketplace(t, dir)
	if file.Name != MarketplaceName || len(file.Plugins) != 2 || file.Plugins[0].Name != "loupe" || file.Plugins[0].Source.Path != "./plugins/loupe" {
		t.Fatalf("marketplace = %+v", file)
	}
	if file.Plugins[0].Policy.Installation != "AVAILABLE" || file.Plugins[0].Policy.Authentication != "ON_INSTALL" || file.Plugins[0].Category != "Productivity" {
		t.Fatalf("policy = %+v", file.Plugins[0])
	}
	target, err := os.Readlink(filepath.Join(MarketplaceDir(dir), "plugins", "loupe"))
	if err != nil || target != current {
		t.Fatalf("link = %q, %v, want %q", target, err, current)
	}
	if _, err := os.Stat(filepath.Join(MarketplaceDir(dir), "plugins", "loupe", codexManifest)); err != nil {
		t.Fatalf("the link does not reach the manifest: %v", err)
	}
}

func TestBuildMarketplaceFollowsANewVersionAndDropsAGonePackage(t *testing.T) {
	dir := t.TempDir()
	putCodex(t, dir, "loupe", "1.0.0")
	putCodex(t, dir, "loupe-lifecycle", "0.3.0")
	if _, err := BuildMarketplace(dir, ""); err != nil {
		t.Fatal(err)
	}
	next := putCodex(t, dir, "loupe", "1.2.0")
	if err := os.RemoveAll(filepath.Join(StoreDir(dir), "loupe-lifecycle")); err != nil {
		t.Fatal(err)
	}

	if _, err := BuildMarketplace(dir, ""); err != nil {
		t.Fatal(err)
	}
	if target, _ := os.Readlink(filepath.Join(MarketplaceDir(dir), "plugins", "loupe")); target != next {
		t.Fatalf("link = %q, want %q", target, next)
	}
	if _, err := os.Lstat(filepath.Join(MarketplaceDir(dir), "plugins", "loupe-lifecycle")); !os.IsNotExist(err) {
		t.Fatalf("the link of a gone package stays: %v", err)
	}
	if file := readMarketplace(t, dir); len(file.Plugins) != 1 {
		t.Fatalf("plugins = %+v", file.Plugins)
	}
	entries, _ := os.ReadDir(filepath.Join(MarketplaceDir(dir), "plugins"))
	if len(entries) != 1 {
		t.Fatalf("entries = %v", entries)
	}
}

func TestMarketplaceIsNotAPackage(t *testing.T) {
	dir := t.TempDir()
	putCodex(t, dir, "loupe", "1.0.0")
	if _, err := BuildMarketplace(dir, ""); err != nil {
		t.Fatal(err)
	}
	if held := Held(dir); !slices.Equal(held, []string{"loupe"}) {
		t.Fatalf("Held = %v", held)
	}
}

func TestBuildMarketplaceSkipsWhatCodexCannotLoad(t *testing.T) {
	dir := t.TempDir()
	putCodex(t, dir, "loupe", "1.0.0")
	put(t, dir, "claude-only", "1.0.0", "")
	put(t, dir, "too-new", "1.0.0", `{"minCli":"9.0.0"}`)

	picks, err := BuildMarketplace(dir, "1.0.0")
	if err != nil || len(picks) != 1 || picks[0].Package != "loupe" {
		t.Fatalf("BuildMarketplace = %+v, %v", picks, err)
	}
}

func TestBuildMarketplaceWritesNothingForAnEmptyStore(t *testing.T) {
	dir := t.TempDir()
	picks, err := BuildMarketplace(dir, "")
	if err != nil || picks != nil {
		t.Fatalf("BuildMarketplace = %+v, %v", picks, err)
	}
	if _, err := os.Stat(MarketplaceDir(dir)); !os.IsNotExist(err) {
		t.Fatalf("marketplace folder: %v", err)
	}
}

func TestHiddenSkillsNameTheSkillsOfOtherPackages(t *testing.T) {
	dir := t.TempDir()
	putCodex(t, dir, "loupe", "1.0.0", "loupe-board", "loupe-inbox")
	putCodex(t, dir, "loupe-lifecycle", "0.3.0", "loupe-stage-merge")

	got := HiddenSkills(dir, "", []string{"loupe"})
	if !slices.Equal(got, []string{"loupe-lifecycle:loupe-stage-merge"}) {
		t.Fatalf("hidden = %v", got)
	}
	if got := HiddenSkills(dir, "", []string{"loupe", "loupe-lifecycle"}); len(got) != 0 {
		t.Fatalf("hidden = %v", got)
	}
	all := HiddenSkills(dir, "", nil)
	if len(all) != 3 {
		t.Fatalf("hidden = %v", all)
	}
}

func TestHiddenSkillsKeepWhatAKeptPackageNeeds(t *testing.T) {
	dir := t.TempDir()
	putCodex(t, dir, "loupe", "1.0.0", "loupe-board")
	p := putCodex(t, dir, "loupe-lifecycle", "0.3.0", "loupe-stage-merge")
	if err := os.WriteFile(filepath.Join(p, packageFile), []byte(`{"requires":{"loupe":">=1.0.0"}}`), 0o644); err != nil {
		t.Fatal(err)
	}

	if got := HiddenSkills(dir, "", []string{"loupe-lifecycle"}); len(got) != 0 {
		t.Fatalf("hidden = %v, want none: loupe is a dependency", got)
	}
}

func TestHomesKeepTheHomeOfASessionUntilItIsOld(t *testing.T) {
	dir := t.TempDir()
	if err := WriteHome(dir, "s1", "/home/a"); err != nil {
		t.Fatal(err)
	}
	if err := WriteHome(dir, "../x", "/home/a"); err == nil {
		t.Fatal("a session id that leaves the folder was accepted")
	}
	if got := Homes(dir); len(got) != 1 || got["s1"].Home != "/home/a" {
		t.Fatalf("Homes = %+v", got)
	}
	if held := Held(dir); len(held) != 0 {
		t.Fatalf("Held = %v", held)
	}

	Prune(dir, time.Now(), nil, nil)
	if len(Homes(dir)) != 1 {
		t.Fatal("a fresh home file was pruned")
	}
	Prune(dir, time.Now().Add(KeepAfterReplace+time.Hour), func(string) bool { return true }, nil)
	if len(Homes(dir)) != 1 {
		t.Fatal("the home file of a live session was pruned")
	}
	Prune(dir, time.Now().Add(KeepAfterReplace+time.Hour), func(string) bool { return false }, nil)
	if len(Homes(dir)) != 0 {
		t.Fatal("an old home file stays")
	}
}
