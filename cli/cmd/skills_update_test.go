package cmd

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
	"unicode/utf8"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/skills"
	"github.com/loupe-ac/loupe/cli/internal/update"
)

// skillArchive packs a package the way skills-release.yml does.
func skillArchive(t *testing.T, version, meta string, extra ...string) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	files := [][2]string{
		{"./.claude-plugin/plugin.json", `{"version":"` + version + `"}`},
		{"./loupe-package.json", meta},
		{"./skills/x/SKILL.md", "skill " + version},
	}
	for _, name := range extra {
		files = append(files, [2]string{name, "x"})
	}
	for _, f := range files {
		if err := tw.WriteHeader(&tar.Header{Name: f[0], Mode: 0o644, Size: int64(len(f[1])), Typeflag: tar.TypeReg}); err != nil {
			t.Fatal(err)
		}
		_, _ = tw.Write([]byte(f[1]))
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}

	return buf.Bytes()
}

// publishSkill adds a skill package release to the fake GitHub. checksumOf is
// the archive the checksum covers, so a test can serve a different one.
func (g *fakeGitHub) publishSkill(pkg, version string, archive, checksumOf []byte) {
	g.mu.Lock()
	defer g.mu.Unlock()
	if g.blobs == nil {
		g.blobs = map[string][]byte{}
	}
	name := pkg + "_" + version + ".tar.gz"
	sum := sha256.Sum256(checksumOf)
	g.blobs["/skills/"+name] = archive
	g.blobs["/skills/sums/"+pkg+version] = []byte(hex.EncodeToString(sum[:]) + "  " + name + "\n")
	g.extra = append(g.extra, update.Release{TagName: "skills/" + pkg + "/v" + version, Assets: []update.Asset{
		{Name: name, URL: g.server.URL + "/skills/" + name},
		{Name: "checksums.txt", URL: g.server.URL + "/skills/sums/" + pkg + version},
	}})
}

func (g *fakeGitHub) publishGoodSkill(t *testing.T, pkg, version, meta string) {
	t.Helper()
	archive := skillArchive(t, version, meta)
	g.publishSkill(pkg, version, archive, archive)
}

// holdSkill makes a version of a package in the store of dir.
func holdSkill(t *testing.T, dir, pkg, version string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Join(dir, "skills", pkg, version), 0o755); err != nil {
		t.Fatal(err)
	}
}

func heldVersion(dir, pkg string) string {
	return skills.Status(dir, "", pkg).Version
}

// skillUpdater is an updater whose router-side hooks record what they get.
func skillUpdater(t *testing.T, gh *fakeGitHub, running string, names ...string) (*updaterHarness, *[]skills.Result) {
	t.Helper()
	h := newTestUpdater(t, gh, running)
	var got []skills.Result
	h.u.skillNames = func() []string { return names }
	h.u.skillsDone = func(r []skills.Result) { got = r }

	return h, &got
}

func TestTheHourlyCheckInstallsANewSkillVersionFromTheOneReleasesList(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
	gh.publishGoodSkill(t, "loupe", "1.1.0", `{"minCli":"1.0.0"}`)
	h, got := skillUpdater(t, gh, "1.0.0", "loupe")
	holdSkill(t, h.u.dir, "loupe", "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if heldVersion(h.u.dir, "loupe") != "1.1.0" {
		t.Fatalf("the store holds %q", heldVersion(h.u.dir, "loupe"))
	}
	if listed, _ := gh.counts(); listed != 1 {
		t.Fatalf("the check listed the releases %d times, want 1", listed)
	}
	if len(*got) != 1 || (*got)[0].Installed != "1.1.0" || (*got)[0].State != skills.StateCurrent {
		t.Fatalf("results = %+v", *got)
	}
	if h.events("skill_package_installed") != 1 || len(h.staged) != 0 {
		t.Fatalf("log: %s, hook calls %v", h.log.String(), h.staged)
	}
	if got := h.u.state(); got.State != updateCurrent {
		t.Fatalf("state = %+v", got)
	}
}

func TestTheHourlyCheckLeavesSkillsAloneWhenAutoUpdateIsOff(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
	gh.publishGoodSkill(t, "loupe", "1.1.0", `{}`)
	h, got := skillUpdater(t, gh, "1.0.0", "loupe")
	h.auto = false
	holdSkill(t, h.u.dir, "loupe", "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if heldVersion(h.u.dir, "loupe") != "1.0.0" || *got != nil {
		t.Fatalf("store %q, results %+v", heldVersion(h.u.dir, "loupe"), *got)
	}
}

func TestAForcedCheckUpdatesSkillsWhateverAutoUpdateSays(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
	gh.publishGoodSkill(t, "loupe", "1.1.0", `{}`)
	h, _ := skillUpdater(t, gh, "1.0.0", "loupe")
	h.auto = false
	holdSkill(t, h.u.dir, "loupe", "1.0.0")
	h.u.setRange("^1.0")
	h.started()

	res := h.u.checkNow(context.Background(), nil)

	if heldVersion(h.u.dir, "loupe") != "1.1.0" {
		t.Fatalf("the store holds %q", heldVersion(h.u.dir, "loupe"))
	}
	if len(res.Skills) != 1 || res.Skills[0].Installed != "1.1.0" || res.Outcome != updateCurrent {
		t.Fatalf("res = %+v", res)
	}
}

func TestAHomebrewBridgeUpdatesSkillsAndLeavesTheCLI(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.publishGoodSkill(t, "loupe", "1.1.0", `{}`)
	h, _ := skillUpdater(t, gh, "1.0.0", "loupe")
	h.u.install = installHomebrew
	holdSkill(t, h.u.dir, "loupe", "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if heldVersion(h.u.dir, "loupe") != "1.1.0" {
		t.Fatalf("the store holds %q", heldVersion(h.u.dir, "loupe"))
	}
	if len(h.staged) != 0 {
		t.Fatalf("hook calls = %v", h.staged)
	}
	if got := h.u.state(); got != (api.HeartbeatUpdate{State: "off", Version: "1.2.0", Install: "homebrew"}) {
		t.Fatalf("state = %+v", got)
	}
}

func TestAFailedSkillUpdateChangesNeitherTheStoreNorTheCLIUpdate(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	good := skillArchive(t, "1.1.0", `{}`)
	gh.publishSkill("loupe", "1.1.0", skillArchive(t, "1.1.0", `{}`, "./other"), good)
	h, got := skillUpdater(t, gh, "1.0.0", "loupe")
	holdSkill(t, h.u.dir, "loupe", "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if heldVersion(h.u.dir, "loupe") != "1.0.0" {
		t.Fatalf("the store holds %q", heldVersion(h.u.dir, "loupe"))
	}
	entries, _ := os.ReadDir(filepath.Join(h.u.dir, "skills", "loupe"))
	if len(entries) != 1 {
		t.Fatalf("the store folder holds %d entries, want 1", len(entries))
	}
	if len(*got) != 1 || (*got)[0].State != skills.StateFailed || !strings.Contains((*got)[0].Reason, "checksum") {
		t.Fatalf("results = %+v", *got)
	}
	if h.events("skill_package_update_failed") != 1 {
		t.Fatalf("log: %s", h.log.String())
	}
	if len(h.staged) != 1 || !strings.HasPrefix(h.staged[0], "1.2.0 ") {
		t.Fatalf("the CLI update did not run: %v", h.staged)
	}
}

func TestADevelopmentBuildUpdatesNoSkills(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
	gh.publishGoodSkill(t, "loupe", "1.1.0", `{}`)
	h, got := skillUpdater(t, gh, "", "loupe")
	holdSkill(t, h.u.dir, "loupe", "1.0.0")

	h.u.setRange("^1.0")
	h.u.start(context.Background())
	t.Cleanup(h.u.stop)
	eventually(t, "the dev state", func() bool { return h.u.state().State == "dev" })
	h.u.stop()

	if listed, _ := gh.counts(); listed != 0 || *got != nil || heldVersion(h.u.dir, "loupe") != "1.0.0" {
		t.Fatalf("results %+v, store %q", *got, heldVersion(h.u.dir, "loupe"))
	}
}

func TestASharedSetupUpdatesItsOwnSkillsUnderAutoUpdate(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
	gh.publishGoodSkill(t, "loupe", "1.1.0", `{}`)
	h, _ := skillUpdater(t, gh, "1.0.0", "loupe")
	h.u.shared = true
	holdSkill(t, h.u.dir, "loupe", "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if heldVersion(h.u.dir, "loupe") != "1.1.0" {
		t.Fatalf("the store holds %q", heldVersion(h.u.dir, "loupe"))
	}
	if _, downloads := gh.counts(); downloads != 2 {
		t.Fatalf("downloads = %d: the shared setup must fetch no CLI archive", downloads)
	}
}

func TestAForcedCheckInASharedSetupUpdatesSkillsAndReturnsTheirResults(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
	gh.publishGoodSkill(t, "loupe", "1.1.0", `{}`)
	h, _ := skillUpdater(t, gh, "1.0.0", "loupe")
	h.auto = false
	h.u.shared = true
	holdSkill(t, h.u.dir, "loupe", "1.0.0")
	h.u.setRange("^1.0")

	res := h.u.run(context.Background(), true)

	if len(res.Skills) != 1 || res.Skills[0].Installed != "1.1.0" || res.Outcome != updateOff || !res.OK {
		t.Fatalf("res = %+v", res)
	}
}

func TestAForcedCheckInASharedSetupReportsAFailedReleaseList(t *testing.T) {
	h, _ := skillUpdater(t, newFakeGitHub(t, "new binary", "cli/v1.0.0"), "1.0.0", "loupe")
	h.auto = false
	h.u.shared = true
	h.u.apiBase = "http://127.0.0.1:1"
	h.u.setRange("^1.0")

	res := h.u.run(context.Background(), true)

	if res.OK || res.Outcome != outcomeFailed || res.Problem == "" {
		t.Fatalf("res = %+v", res)
	}
}

func TestAForcedCheckUpdatesSkillsWhenTheRangeRulesOutARelease(t *testing.T) {
	for name, cliRange := range map[string]string{"no range yet": "", "above the range": "^0.5"} {
		t.Run(name, func(t *testing.T) {
			gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
			gh.publishGoodSkill(t, "loupe", "1.1.0", `{}`)
			h, _ := skillUpdater(t, gh, "1.0.0", "loupe")
			h.auto = false
			holdSkill(t, h.u.dir, "loupe", "1.0.0")
			if cliRange != "" {
				h.u.setRange(cliRange)
			}

			res := h.u.run(context.Background(), true)

			if len(res.Skills) != 1 || res.Skills[0].Installed != "1.1.0" || res.Outcome != outcomeFailed {
				t.Fatalf("res = %+v", res)
			}
		})
	}
}

func TestTheRouterNamesTheHeldPackagesAndThoseTheProjectsList(t *testing.T) {
	r, _ := packageStore(t, api.Site{ID: "p", Slug: "p", SkillPackages: []string{"loupe", "extra"}})

	if got := strings.Join(r.skillNames(), ","); got != "extra,loupe,loupe-lifecycle" {
		t.Fatalf("names = %s", got)
	}
}

func TestTheHeartbeatCarriesTheStateOfEachSkillPackage(t *testing.T) {
	r, dir := packageStore(t)
	holdSkill(t, dir, "loupe", "1.2.0")
	r.recordSkillResults([]skills.Result{{Package: "loupe-lifecycle", Version: "1.0.0", State: skills.StateFailed, Reason: "needs loupe 2.0.0 or later"}})
	client := &fakeHeartbeats{}
	startHeartbeater(t, client, time.Minute, func(h *heartbeater) { h.skills = r.skillReports })

	client.mu.Lock()
	defer client.mu.Unlock()
	want := []api.SkillReport{
		{Package: "loupe", Version: "1.2.0", State: "current"},
		{Package: "loupe-lifecycle", Version: "1.0.0", State: "failed", Reason: "needs loupe 2.0.0 or later"},
	}
	if got := client.sent[0].Skills; len(got) != 2 || got[0] != want[0] || got[1] != want[1] {
		t.Fatalf("skills = %+v, want %+v", got, want)
	}
	body, _ := json.Marshal(client.sent[0])
	if !strings.Contains(string(body), `"skills":[{"package":"loupe","version":"1.2.0","state":"current"},{"package":"loupe-lifecycle","version":"1.0.0","state":"failed","reason":"needs loupe 2.0.0 or later"}]`) {
		t.Fatalf("body = %s", body)
	}
}

func TestTheHeartbeatSendsNoSkillsKeyWhenThereAreNone(t *testing.T) {
	body, _ := json.Marshal(api.Heartbeat{})
	if strings.Contains(string(body), "skills") {
		t.Fatalf("body = %s", body)
	}
	r, _ := skillRouter(nil)
	if r.skillReports() != nil {
		t.Fatal("a router with no store reports skills")
	}
	r.skills.dir = t.TempDir()
	if got := r.skillReports(); got == nil || len(got) != 0 {
		t.Fatalf("a development build with an empty store reports %#v, want an empty list that clears the stored rows", got)
	}
	r.skills.cliVersion = "1.0.0"
	if got := r.skillReports(); got == nil || len(got) != 0 {
		t.Fatalf("reports = %#v", got)
	}
}

// The server refuses the whole heartbeat for a blank version, a reason above 500
// bytes and more than 20 rows.
func TestTheHeartbeatRowsStayInsideTheLimitsOfTheServer(t *testing.T) {
	r, dir := packageStore(t)
	holdSkill(t, dir, "Not A Package", "1.0.0")
	for i := range 25 {
		holdSkill(t, dir, "extra-"+string(rune('a'+i)), "1.0.0")
	}
	r.recordSkillResults([]skills.Result{{Package: "extra-a", Version: "1.0.0", State: skills.StateFailed, Reason: strings.Repeat("é", 400)}})
	if err := os.RemoveAll(filepath.Join(dir, "skills", "extra-b", "1.0.0")); err != nil {
		t.Fatal(err)
	}
	holdSkill(t, dir, "extra-b", "9.9.9")
	if err := os.WriteFile(filepath.Join(dir, "skills", "extra-b", "9.9.9", "loupe-package.json"), []byte(`{"minCli":"99.0.0"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	r.skills.cliVersion = "1.0.0"

	got := r.skillReports()

	if len(got) != 20 {
		t.Fatalf("%d rows, want 20", len(got))
	}
	for _, row := range got {
		if row.Version == "" || len(row.Reason) > 500 || !skills.ValidName(row.Package) {
			t.Fatalf("row = %+v", row)
		}
		switch row.Package {
		case "extra-a":
			if row.State != "failed" || !utf8.ValidString(row.Reason) {
				t.Fatalf("row = %+v", row)
			}
		case "extra-b":
			if row.Version != "none" || row.State != "failed" {
				t.Fatalf("a package with no usable version: %+v", row)
			}
		}
	}
}

func TestTheReportShowsAFailedPackageUntilAnUpdateClearsIt(t *testing.T) {
	r, _ := packageStore(t)
	r.recordSkillResults([]skills.Result{{Package: "loupe", Version: "1.0.0", State: skills.StateFailed, Reason: "bad"}})
	if got := r.skillReports(); got[0].State != "failed" || got[0].Reason != "bad" || got[0].Version != "1.0.0" {
		t.Fatalf("reports = %+v", got)
	}

	r.recordSkillResults([]skills.Result{{Package: "loupe", State: skills.StateCurrent}})

	if got := r.skillReports(); got[0].State != "current" || got[0].Reason != "" {
		t.Fatalf("reports = %+v", got)
	}
}

// `loupe update` on a Homebrew install writes the store from its own process, so
// the bridge sees the update only in the store.
func TestAVersionInstalledOutsideTheBridgeClearsItsOldFailure(t *testing.T) {
	r, dir := packageStore(t)
	r.recordSkillResults([]skills.Result{{Package: "loupe", Version: "1.0.0", State: skills.StateFailed, Reason: "bad"}})

	holdSkill(t, dir, "loupe", "1.1.0")

	if got := r.skillReports(); got[0].State != "current" || got[0].Reason != "" || got[0].Version != "1.1.0" {
		t.Fatalf("reports = %+v", got)
	}
}

func TestStatusNamesTheBridgeOfEachSkillFailure(t *testing.T) {
	shortConfigHome(t)
	holdSkill(t, mustConfigDir(t), "loupe", "1.0.0")
	one, two, three := validStatus("one"), validStatus("two"), validStatus("three")
	one.Skills = []api.SkillReport{{Package: "loupe", Version: "1.0.0", State: "failed", Reason: "first"}}
	two.Skills = []api.SkillReport{{Package: "loupe", Version: "1.0.0", State: "current"}}
	three.Skills = []api.SkillReport{{Package: "loupe", Version: "1.0.0", State: "failed", Reason: "second"}}

	var out bytes.Buffer
	noteSkills(&out, []bridgeReport{{status: one}, {status: two}, {status: three}})

	want := "Skills:      loupe 1.0.0 failed: first (bridge one)\nSkills:      loupe 1.0.0 failed: second (bridge three)\n"
	if out.String() != want {
		t.Fatalf("out = %q, want %q", out.String(), want)
	}
}

func TestStatusTakesTheVersionOfABridgeFromItsReport(t *testing.T) {
	shortConfigHome(t)
	holdSkill(t, mustConfigDir(t), "loupe", "1.0.0")
	same, other, bad, gone := validStatus("same"), validStatus("other"), validStatus("bad"), validStatus("gone")
	same.Skills = []api.SkillReport{{Package: "loupe", Version: "1.0.0", State: "current"}}
	other.Skills = []api.SkillReport{
		{Package: "loupe", Version: "2.0.0", State: "current"},
		{Package: "extra", Version: "3.0.0", State: "current"},
	}
	bad.Skills = []api.SkillReport{{Package: "failing", Version: "4.0.0", State: "failed", Reason: "r"}}
	gone.Skills = []api.SkillReport{{Package: "absent", Version: noSkillVersion, State: "failed", Reason: "r"}}

	var out bytes.Buffer
	noteSkills(&out, []bridgeReport{{status: same}, {status: other}, {status: bad}, {status: gone}})

	want := "Skills:      absent failed: r (bridge gone)\n" +
		"Skills:      extra 3.0.0 current (bridge other)\n" +
		"Skills:      failing 4.0.0 failed: r (bridge bad)\n" +
		"Skills:      loupe 1.0.0 current\n" +
		"Skills:      loupe 2.0.0 current (bridge other)\n"
	if out.String() != want {
		t.Fatalf("out = %q, want %q", out.String(), want)
	}
}

func TestStatusPrintsEachSkillPackageWithItsVersionStateAndReason(t *testing.T) {
	shortConfigHome(t)
	dir := mustConfigDir(t)
	holdSkill(t, dir, "loupe", "1.2.0")
	holdSkill(t, dir, "loupe", "1.1.0")
	holdSkill(t, dir, "loupe-lifecycle", "1.0.0")
	bad := validStatus("alpha")
	bad.Skills = []api.SkillReport{{Package: "loupe-lifecycle", Version: "1.0.0", State: "failed", Reason: "no release of loupe-lifecycle exists"}}

	var out bytes.Buffer
	noteSkills(&out, []bridgeReport{{sock: "s", status: bad}})

	want := "Skills:      loupe 1.2.0 current\nSkills:      loupe-lifecycle 1.0.0 failed: no release of loupe-lifecycle exists (bridge alpha)\n"
	if out.String() != want {
		t.Fatalf("out = %q, want %q", out.String(), want)
	}
}

func TestStatusSaysWhenTheStoreHoldsNoPackage(t *testing.T) {
	shortConfigHome(t)

	var out bytes.Buffer
	noteSkills(&out, nil)

	if out.String() != "Skills:      none in the store\n" {
		t.Fatalf("out = %q", out.String())
	}
}

func TestStatusAsksTheRunningBridgeForItsSkillReports(t *testing.T) {
	shortConfigHome(t)
	st := validStatus("alpha")
	st.Skills = []api.SkillReport{{Package: "loupe", Version: "1.0.0", State: "failed", Reason: "r"}}
	fakeBridge(t, "one.yaml", statusOps(st))
	holdSkill(t, mustConfigDir(t), "loupe", "1.0.0")

	reports, err := collectBridges()
	var out bytes.Buffer
	noteSkills(&out, reports)

	if err != nil || out.String() != "Skills:      loupe 1.0.0 failed: r (bridge alpha)\n" {
		t.Fatalf("out = %q, err = %v", out.String(), err)
	}
}

func TestUpdateWithNoBridgeUpdatesSkillsBeforeTheBinary(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0", "cli/v1.2.0")
	gh.publishGoodSkill(t, "loupe", "1.1.0", `{}`)
	gh.publishGoodSkill(t, "loupe-lifecycle", "1.0.0", `{"requires":{"loupe":">=2.0.0"}}`)
	self, exe := selfUpdateAgainst(t, gh, "1.0.0")
	holdSkill(t, mustConfigDir(t), "loupe", "1.0.0")
	holdSkill(t, mustConfigDir(t), "loupe-lifecycle", "0.9.0")
	self.sites = func(context.Context) ([]api.Site, error) {
		return []api.Site{{ID: "p", SkillPackages: []string{"loupe", "loupe-lifecycle", "ghost"}}}, nil
	}

	out, err := updateCmd(t, withServerRange(self, "^1.0"))

	if err != nil {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	for _, want := range []string{
		"skills: ghost failed: no release of ghost exists\n",
		"skills: loupe 1.1.0 installed\n",
		"skills: loupe-lifecycle 0.9.0 failed: loupe-lifecycle 1.0.0 needs loupe >=2.0.0",
		"installed 1.2.0 over " + exe,
	} {
		if !strings.Contains(out, want) {
			t.Fatalf("out lacks %q:\n%s", want, out)
		}
	}
	if strings.Index(out, "skills: loupe 1.1.0") > strings.Index(out, "installed 1.2.0") {
		t.Fatalf("skills came after the binary:\n%s", out)
	}
	if data, _ := os.ReadFile(exe); string(data) != "new binary" {
		t.Fatalf("binary = %q", data)
	}
	if listed, _ := gh.counts(); listed != 1 {
		t.Fatalf("listed the releases %d times, want 1", listed)
	}
}

func TestAFailedSkillUpdateDoesNotFailTheBinaryUpdate(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.publishSkill("loupe", "1.1.0", skillArchive(t, "1.1.0", `{}`), []byte("other"))
	self, exe := selfUpdateAgainst(t, gh, "1.0.0")
	dir := mustConfigDir(t)
	holdSkill(t, dir, "loupe", "1.0.0")

	out, err := updateCmd(t, withServerRange(self, "^1.0"))

	if err != nil || !strings.Contains(out, "skills: loupe 1.0.0 failed:") || !strings.Contains(out, "installed 1.2.0 over "+exe) {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if heldVersion(dir, "loupe") != "1.0.0" {
		t.Fatalf("the store holds %q", heldVersion(dir, "loupe"))
	}
}

func TestUpdateOnHomebrewUpdatesSkillsThenPrintsTheBrewLine(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.publishGoodSkill(t, "loupe", "1.1.0", `{}`)
	self, _ := selfUpdateAgainst(t, gh, "1.0.0")
	keg, link := homebrewBinary(t)
	self.executable = func() (string, error) { return link, nil }
	holdSkill(t, mustConfigDir(t), "loupe", "1.0.0")

	out, err := updateCmd(t, withServerRange(self, "^1.0"))

	if err == nil || err.Error() != "loupe was installed with Homebrew. Run: brew upgrade loupe" {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(out, "skills: loupe 1.1.0 installed") {
		t.Fatalf("out = %q", out)
	}
	if heldVersion(mustConfigDir(t), "loupe") != "1.1.0" {
		t.Fatal("the skill did not update")
	}
	if data, _ := os.ReadFile(keg); string(data) != "old" {
		t.Fatalf("binary = %q", data)
	}
}

func TestUpdatePrintsTheSkillLinesOfEachBridge(t *testing.T) {
	shortConfigHome(t)
	serveUpdateTest(t, filepath.Join(t.TempDir(), "rules.yaml"), func(context.Context, func(updateResult)) updateResult {
		return updateResult{OK: true, From: "1.0.0", Outcome: updateCurrent, Skills: []skills.Result{
			{Package: "loupe", Version: "1.1.0", State: "current", Installed: "1.1.0"},
			{Package: "loupe-lifecycle", Version: "1.0.0", State: "failed", Reason: "needs more"},
			{Package: "other", Version: "2.0.0", State: "current"},
		}}
	})

	out, err := updateCmd(t, noSelfUpdate(t))

	if err != nil {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	for _, want := range []string{"skills: loupe 1.1.0 installed\n", "skills: loupe-lifecycle 1.0.0 failed: needs more\n", "skills: other 2.0.0 current\n"} {
		if !strings.Contains(out, want) {
			t.Fatalf("out lacks %q:\n%s", want, out)
		}
	}
}
