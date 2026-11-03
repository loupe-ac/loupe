package cmd

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/spf13/cobra"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/loupe-ac/loupe/cli/internal/skills"
)

func skillRouter(list func(context.Context) ([]api.Site, error)) (*router, *bytes.Buffer) {
	var buf bytes.Buffer
	r := &router{log: debugLogger(&buf)}
	r.skills.list = list

	return r, &buf
}

func flag(v bool) *bool { return &v }

func TestAProjectTakesThePackagesTheAppListsForIt(t *testing.T) {
	r, _ := skillRouter(func(context.Context) ([]api.Site, error) {
		return []api.Site{
			{ID: "p1", Slug: "life", SkillPackages: []string{"loupe", "loupe-lifecycle"}, NeedsProfile: flag(true)},
			{ID: "p2", Slug: "plain", SkillPackages: []string{"loupe"}, NeedsProfile: flag(false)},
		}, nil
	})
	r.loadSkills(context.Background())
	held := []string{"loupe", "loupe-lifecycle", "extra"}

	if got := r.projectPackages("life", held); !slices.Equal(got, []string{"loupe", "loupe-lifecycle"}) {
		t.Fatalf("life = %v", got)
	}
	if got := r.projectPackages("P2", held); !slices.Equal(got, []string{"loupe"}) {
		t.Fatalf("plain = %v", got)
	}
	site, _ := r.projectSite("plain")
	if site.ProfileNeeded() {
		t.Fatal("a Simple project needs no profile")
	}
}

func TestAProjectWithNoListTakesEveryPackageAndNeedsAProfile(t *testing.T) {
	r, _ := skillRouter(func(context.Context) ([]api.Site, error) {
		return []api.Site{{ID: "p1", Slug: "old"}}, nil
	})
	r.loadSkills(context.Background())
	held := []string{"loupe", "loupe-lifecycle"}

	if got := r.projectPackages("old", held); !slices.Equal(got, held) {
		t.Fatalf("old = %v", got)
	}
	if got := r.projectPackages("unlisted", held); !slices.Equal(got, held) {
		t.Fatalf("unlisted = %v", got)
	}
	site, listed := r.projectSite("old")
	if !listed || !site.ProfileNeeded() {
		t.Fatalf("listed = %v, needs profile = %v", listed, site.ProfileNeeded())
	}
	got := r.projectPackages("old", held)
	got[0] = "changed"
	if held[0] != "loupe" {
		t.Fatal("the answer shares the memory of the held list")
	}
}

func TestAFailedReadKeepsTheLastAnswerAndLogsOnePerStreak(t *testing.T) {
	fail := false
	r, buf := skillRouter(func(context.Context) ([]api.Site, error) {
		if fail {
			return nil, errors.New("offline")
		}

		return []api.Site{{ID: "p1", Slug: "life", SkillPackages: []string{"loupe"}}}, nil
	})
	r.loadSkills(context.Background())
	fail = true
	r.loadSkills(context.Background())
	r.loadSkills(context.Background())

	if got := r.projectPackages("life", []string{"loupe", "loupe-lifecycle"}); !slices.Equal(got, []string{"loupe"}) {
		t.Fatalf("kept = %v", got)
	}
	if n := strings.Count(buf.String(), "skill_packages_read_failed"); n != 1 {
		t.Fatalf("failures logged = %d, log = %q", n, buf)
	}
	fail = false
	r.loadSkills(context.Background())
	if !strings.Contains(buf.String(), "skill_packages_read") || strings.Count(buf.String(), "skill_packages_read_failed") != 1 {
		t.Fatalf("log = %q", buf)
	}
}

func TestTheSkillLoopReadsAfterEachInterval(t *testing.T) {
	reads := make(chan struct{}, 4)
	r, _ := skillRouter(func(context.Context) ([]api.Site, error) {
		reads <- struct{}{}

		return nil, nil
	})
	tick := make(chan time.Time)
	waited := make(chan time.Duration, 4)
	r.skills.after = func(d time.Duration) <-chan time.Time {
		waited <- d

		return tick
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { r.skillLoop(ctx); close(done) }()

	if d := <-waited; d != time.Hour {
		t.Fatalf("waited = %v", d)
	}
	if len(reads) != 0 {
		t.Fatal("the loop read before its first interval")
	}
	tick <- time.Now()
	<-reads
	<-waited
	cancel()
	<-done
}

// listAnswer is one read of the project list.
type listAnswer func() ([]api.Site, error)

func packages(name string) listAnswer {
	return func() ([]api.Site, error) {
		return []api.Site{{ID: "p1", Slug: "life", SkillPackages: []string{name}}}, nil
	}
}

func offline() ([]api.Site, error) { return nil, errors.New("offline") }

// overlappingReads runs a read that answers with later while a read that began
// earlier waits, and then lets the earlier read answer with earlier.
func overlappingReads(r *router, earlier, later listAnswer) {
	entered, held := make(chan struct{}), make(chan struct{})
	first := true
	r.skills.list = func(context.Context) ([]api.Site, error) {
		if first {
			first = false
			close(entered)
			<-held

			return earlier()
		}

		return later()
	}
	done := make(chan struct{})
	go func() { r.loadSkills(context.Background()); close(done) }()
	<-entered
	r.loadSkills(context.Background())
	close(held)
	<-done
}

func TestALaterReadBeatsAnEarlierReadThatAnswersLast(t *testing.T) {
	r, _ := skillRouter(nil)
	overlappingReads(r, packages("older"), packages("newer"))

	site, _ := r.projectSite("life")
	if !slices.Equal(site.SkillPackages, []string{"newer"}) {
		t.Fatalf("kept = %v", site.SkillPackages)
	}
}

func TestAnEarlierReadCountsWhenTheLaterReadFailed(t *testing.T) {
	r, buf := skillRouter(nil)
	overlappingReads(r, packages("older"), offline)

	site, _ := r.projectSite("life")
	if !slices.Equal(site.SkillPackages, []string{"older"}) {
		t.Fatalf("kept = %v", site.SkillPackages)
	}
	if !strings.Contains(buf.String(), "skill_packages_read_failed") {
		t.Fatalf("log = %q", buf)
	}
}

func TestAnEarlierFailureLeavesALaterAnswerAlone(t *testing.T) {
	r, buf := skillRouter(nil)
	overlappingReads(r, offline, packages("newer"))

	site, _ := r.projectSite("life")
	if !slices.Equal(site.SkillPackages, []string{"newer"}) {
		t.Fatalf("kept = %v", site.SkillPackages)
	}
	if strings.Contains(buf.String(), "skill_packages_read_failed") {
		t.Fatalf("log = %q", buf)
	}
}

// A reload dispatches work when it swaps the set, so its read must end first.
func TestAReloadReadsThePackagesBeforeItSwapsTheSet(t *testing.T) {
	h := newHarness(t)
	old := h.router.rules()
	var during *rules.Set
	h.router.skills.list = func(context.Context) ([]api.Site, error) {
		during = h.router.rules()

		return []api.Site{{ID: testProject, Slug: "loupe", SkillPackages: []string{"loupe"}}}, nil
	}
	res := h.reload(t, twoRuleFile)
	if !res.OK {
		t.Fatalf("reload = %+v", res)
	}
	if during != old || h.router.rules() == old {
		t.Fatal("the read did not run before the swap")
	}
	if site, listed := h.router.projectSite("loupe"); !listed || !slices.Equal(site.SkillPackages, []string{"loupe"}) {
		t.Fatalf("listed = %v, site = %+v", listed, site)
	}
}

func TestAFailedPackageReadFailsNoReload(t *testing.T) {
	h := newHarness(t)
	h.router.skills.list = func(context.Context) ([]api.Site, error) { return nil, errors.New("offline") }
	if res := h.reload(t, twoRuleFile); !res.OK {
		t.Fatalf("reload = %+v", res)
	}
}

func TestARejectedReloadReadsNoPackages(t *testing.T) {
	h := newHarness(t)
	h.router.skills.list = func(context.Context) ([]api.Site, error) {
		t.Error("a rejected reload read the project list")

		return nil, nil
	}
	if res := h.reload(t, "projects: {}\nwork:\n  plan: {}\n"); res.OK {
		t.Fatalf("reload = %+v", res)
	}
}

// Work can dispatch from the moment the heartbeat goes out, so the first read
// must end before it.
func TestTheBridgeReadsThePackagesBeforeItStartsWork(t *testing.T) {
	injectVersion(t, "1.0.0")
	fake := &fakeLoupe{flags: `{"bridge.heartbeat_interval_seconds":3600}`}
	release := make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if req.URL.Path == "/api/v1/bridge/projects" {
			select {
			case <-release:
			case <-req.Context().Done():
				return
			}
			fmt.Fprintf(w, `{"sites":[{"id":%q,"slug":"loupe","skillPackages":["loupe"]}]}`, testProject)

			return
		}
		fake.serve(w, req)
	}))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)
	body := "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\nwork:\n  plan:\n    prompt: go\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}
	log := &syncBuffer{}
	worker := &fakeWorker{result: finishedRun}
	r := withRules(&router{log: newBridgeLogger(log), worker: worker.ops(), bridgeID: testBridgeID}, set)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()

	time.Sleep(200 * time.Millisecond)
	if strings.Contains(log.String(), `"event":"heartbeat_sent"`) {
		t.Fatal("the bridge sent its heartbeat before the first read ended")
	}
	close(release)
	eventually(t, "the start heartbeat", func() bool {
		return strings.Contains(log.String(), `"event":"heartbeat_sent"`)
	})
	if site, listed := r.projectSite("loupe"); !listed || !slices.Equal(site.SkillPackages, []string{"loupe"}) {
		t.Fatalf("listed = %v, site = %+v", listed, site)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestARouterWithNoListReadsNothing(t *testing.T) {
	r, _ := skillRouter(nil)
	r.loadSkills(context.Background())

	if _, listed := r.projectSite("any"); listed {
		t.Fatal("a router with no list knows a project")
	}
}

// packageStore makes a store with the two packages at one version each and a
// router that reads it.
func packageStore(t *testing.T, sites ...api.Site) (*router, string) {
	t.Helper()
	dir := t.TempDir()
	for _, pkg := range []string{"loupe", "loupe-lifecycle"} {
		if err := os.MkdirAll(filepath.Join(dir, "skills", pkg, "1.0.0"), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	r, _ := skillRouter(func(context.Context) ([]api.Site, error) { return sites, nil })
	r.skills.dir = dir
	r.skills.exe = func() (string, error) { return "/bin/loupe", nil }
	r.skills.processes = noSessions
	r.loadSkills(context.Background())

	return r, dir
}

// noSessions is a process list that names no session.
func noSessions(context.Context) (string, error) { return "1 /sbin/init\n", nil }

// ageRecord makes the record of a session look written that long ago.
func ageRecord(t *testing.T, dir, session string, age time.Duration) {
	t.Helper()
	at := time.Now().Add(-age)
	if err := os.Chtimes(filepath.Join(dir, "skills", ".records", session+".json"), at, at); err != nil {
		t.Fatal(err)
	}
}

func TestAClaudeWorkerGetsThePackagesOfItsProject(t *testing.T) {
	r, dir := packageStore(t, api.Site{ID: "p1", Slug: "simple", SkillPackages: []string{"loupe"}})
	spec := workerSpec{sessionID: "s"}

	if err := r.givePackages(&spec, pending{project: "simple"}); err != nil {
		t.Fatal(err)
	}
	if len(spec.packages) != 1 || spec.packages[0].Dir != filepath.Join(dir, "skills", "loupe", "1.0.0") {
		t.Fatalf("packages = %+v, want loupe alone", spec.packages)
	}
	if !strings.Contains(spec.mcpConfig, `"command":"/bin/loupe"`) || !strings.Contains(spec.mcpConfig, `"args":["mcp"]`) {
		t.Fatalf("mcpConfig = %s", spec.mcpConfig)
	}
	h := spec.harnessSpec(nil)
	if len(h.PluginDirs) != 1 || h.MCPConfig != spec.mcpConfig {
		t.Fatalf("harness spec = %+v", h)
	}
}

func TestAProjectWithNoListGetsEveryPackageHeld(t *testing.T) {
	r, _ := packageStore(t)
	spec := workerSpec{sessionID: "s"}

	if err := r.givePackages(&spec, pending{project: "unlisted"}); err != nil || len(spec.packages) != 2 {
		t.Fatalf("packages = %+v, err = %v, want both", spec.packages, err)
	}
}

func TestAProjectThatNamesAMissingPackageFailsWithTheFix(t *testing.T) {
	r, _ := packageStore(t, api.Site{ID: "p1", Slug: "x", SkillPackages: []string{"loupe", "market-x"}})
	spec := workerSpec{}

	err := r.givePackages(&spec, pending{project: "x"})
	if err == nil || !strings.Contains(err.Error(), "market-x") || !strings.Contains(err.Error(), "loupe update") {
		t.Fatalf("err = %v, want the package and the fix", err)
	}
	if spec.packages != nil {
		t.Fatalf("packages = %+v, want none", spec.packages)
	}
}

func TestACodexWorkerAndAnEmptyStoreGetNoPackages(t *testing.T) {
	r, _ := packageStore(t)
	spec := workerSpec{harnessName: rules.HarnessCodex}
	if err := r.givePackages(&spec, pending{project: "x"}); err != nil || spec.packages != nil || spec.mcpConfig != "" {
		t.Fatalf("a Codex worker got %+v, %q, %v", spec.packages, spec.mcpConfig, err)
	}

	empty, _ := skillRouter(nil)
	empty.skills.dir = t.TempDir()
	spec = workerSpec{}
	if err := empty.givePackages(&spec, pending{project: "x"}); err != nil || spec.packages != nil {
		t.Fatalf("an empty store gave %+v, %v, want the account's plugin", spec.packages, err)
	}
	none, _ := skillRouter(nil)
	if err := none.givePackages(&spec, pending{project: "x"}); err != nil || spec.packages != nil {
		t.Fatalf("a router with no store gave %+v, %v", spec.packages, err)
	}
}

func TestAResumeKeepsTheVersionsItsSessionStartedWith(t *testing.T) {
	r, dir := packageStore(t)
	if err := os.MkdirAll(filepath.Join(dir, "skills", "loupe", "0.9.0"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := skills.WriteRecord(dir, "s", []skills.Pick{{Package: "loupe", Version: "0.9.0"}}); err != nil {
		t.Fatal(err)
	}

	spec := workerSpec{resume: true, sessionID: "s"}
	if err := r.givePackages(&spec, pending{project: "x"}); err != nil {
		t.Fatal(err)
	}
	if len(spec.packages) != 1 || spec.packages[0].Version != "0.9.0" {
		t.Fatalf("packages = %+v, want loupe 0.9.0", spec.packages)
	}

	// With the folder gone the resume takes the current version and says so.
	if err := os.RemoveAll(filepath.Join(dir, "skills", "loupe", "0.9.0")); err != nil {
		t.Fatal(err)
	}
	var buf bytes.Buffer
	r.log = debugLogger(&buf)
	spec = workerSpec{resume: true, sessionID: "s"}
	if err := r.givePackages(&spec, pending{project: "x"}); err != nil {
		t.Fatal(err)
	}
	if len(spec.packages) != 2 || !strings.Contains(buf.String(), "skill_packages_current_on_resume") {
		t.Fatalf("packages = %+v, log = %s", spec.packages, buf.String())
	}
}

// replaceLoupe puts a newer loupe in the store, which replaced the older
// versions long ago.
func replaceLoupe(t *testing.T, dir, version, meta string) {
	t.Helper()
	newer := filepath.Join(dir, "skills", "loupe", version)
	if err := os.MkdirAll(newer, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(newer, "loupe-package.json"), []byte(meta), 0o644); err != nil {
		t.Fatal(err)
	}
	long := time.Now().Add(-90 * 24 * time.Hour)
	if err := os.Chtimes(newer, long, long); err != nil {
		t.Fatal(err)
	}
}

// A worker that a former image started has only its record, because the
// handover carries no packages. The record stays as long as the worker, so a
// later prune still finds the version.
func TestThePruneKeepsTheVersionsThatTheRecordOfALiveRunNames(t *testing.T) {
	r, dir := packageStore(t)
	old := filepath.Join(dir, "skills", "loupe", "1.0.0")
	replaceLoupe(t, dir, "2.0.0", "{}")
	if err := skills.WriteRecord(dir, "s", []skills.Pick{{Package: "loupe", Version: "1.0.0"}}); err != nil {
		t.Fatal(err)
	}
	ageRecord(t, dir, "s", 2*skills.KeepAfterReplace)
	r.live = map[string]liveRun{"a": {p: pending{spec: workerSpec{sessionID: "s"}}}}

	for range 2 {
		r.pruneSkills(time.Now())
		if !exists(old) {
			t.Fatal("a live run's version was deleted")
		}
	}
	if skills.ReadRecord(dir, "s") == nil {
		t.Fatal("the record of a live run was deleted")
	}
	r.live = nil
	r.pruneSkills(time.Now())
	if exists(old) {
		t.Fatal("the version stayed after its run ended")
	}
	if skills.ReadRecord(dir, "s") != nil {
		t.Fatal("the old record stayed after its run ended")
	}
}

// An interactive session is in no live run, and can outlive the bridge that
// launched it, so a process that names it is what keeps its versions.
func TestThePruneKeepsTheVersionsOfASessionThatAProcessNames(t *testing.T) {
	for _, flag := range []string{"--session-id s", "--session-id=s", "--resume s"} {
		r, dir := packageStore(t)
		old := filepath.Join(dir, "skills", "loupe", "1.0.0")
		replaceLoupe(t, dir, "2.0.0", "{}")
		if err := skills.WriteRecord(dir, "s", []skills.Pick{{Package: "loupe", Version: "1.0.0"}}); err != nil {
			t.Fatal(err)
		}
		ageRecord(t, dir, "s", 2*skills.KeepAfterReplace)
		r.skills.processes = func(context.Context) (string, error) { return "1 /sbin/init\n2 claude " + flag + "\n", nil }

		for range 2 {
			r.pruneSkills(time.Now())
			if !exists(old) || skills.ReadRecord(dir, "s") == nil {
				t.Fatalf("%s: the version or the record of a running session was deleted", flag)
			}
		}
	}
}

// The launcher of an interactive session returns while the session runs on.
func TestThePruneKeepsTheVersionsOfASessionAfterItsLauncherReturned(t *testing.T) {
	h, _ := launchHarness(t, `[sh, -c, 'exit 0', sh, '{script}']`)
	store := t.TempDir()
	old := filepath.Join(store, "skills", "loupe", "1.0.0")
	if err := os.MkdirAll(old, 0o755); err != nil {
		t.Fatal(err)
	}
	h.router.skills.dir = store
	h.router.skills.exe = func() (string, error) { return "/bin/loupe", nil }
	processes := "1 claude --session-id " + testSession + "\n"
	h.router.skills.processes = func(context.Context) (string, error) { return processes, nil }

	h.send(movedPayload(87, "backlog", "next", "agent"))
	h.only(t, "session_launched")
	eventually(t, "the end of the launch hold", func() bool {
		h.router.skills.mu.Lock()
		defer h.router.skills.mu.Unlock()

		return len(h.router.skills.holds) == 0
	})
	replaceLoupe(t, store, "2.0.0", "{}")
	ageRecord(t, store, testSession, 2*skills.KeepAfterReplace)

	h.router.pruneSkills(time.Now())
	if !exists(old) {
		t.Fatal("the version of an open session was deleted after its launcher returned")
	}

	processes = "1 /sbin/init\n"
	h.router.pruneSkills(time.Now())
	if exists(old) {
		t.Fatal("the version stayed after the session ended")
	}
}

// A session with no record outlives what the prune can see, so it never starts.
func TestALaunchWhoseRecordCannotBeWrittenDoesNotOpen(t *testing.T) {
	h, _ := launchHarness(t, `[sh, -c, 'exit 0', sh, '{script}']`)
	store := unwritableRecords(t)
	old := filepath.Join(store, "skills", "loupe", "1.0.0")
	h.router.skills.dir = store
	h.router.skills.exe = func() (string, error) { return "/bin/loupe", nil }
	h.router.skills.processes = noSessions

	h.send(movedPayload(87, "backlog", "next", "agent"))
	line := h.only(t, "session_launch_failed")
	if reason, _ := line["reason"].(string); !strings.Contains(reason, "record the skill packages of session "+testSession) {
		t.Fatalf("reason = %q", reason)
	}
	if left := h.scripts(t); len(left) != 0 {
		t.Fatalf("the launch left %v", left)
	}
	h.router.skills.mu.Lock()
	holds := len(h.router.skills.holds)
	h.router.skills.mu.Unlock()
	if holds != 0 {
		t.Fatalf("%d holds, want none", holds)
	}
	replaceLoupe(t, store, "2.0.0", "{}")
	h.router.pruneSkills(time.Now())
	if exists(old) {
		t.Fatal("a version stayed for a session that never opened")
	}
}

func TestAWorkerWhoseRecordCannotBeWrittenDoesNotStart(t *testing.T) {
	h := newHarness(t)
	h.router.skills.dir = unwritableRecords(t)
	h.router.skills.exe = func() (string, error) { return "/bin/loupe", nil }
	started := make(chan struct{}, 1)
	h.router.worker.run = func(context.Context, workerSpec, func(workerProc)) workerResult {
		started <- struct{}{}

		return workerResult{hasResult: true}
	}

	h.router.onData([]byte(cardMoved(87)))
	eventually(t, "the failed run", func() bool { return len(h.events(t, "worker_failed")) == 1 })
	if reason, _ := h.only(t, "worker_failed")["error"].(string); !strings.Contains(reason, "record the skill packages") {
		t.Fatalf("error = %q", reason)
	}
	if len(started) != 0 {
		t.Fatal("a worker started with no record")
	}
}

// unwritableRecords is a store with one version, whose records folder is a file.
func unwritableRecords(t *testing.T) string {
	t.Helper()
	store := t.TempDir()
	if err := os.MkdirAll(filepath.Join(store, "skills", "loupe", "1.0.0"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(store, "skills", ".records"), nil, 0o600); err != nil {
		t.Fatal(err)
	}

	return store
}

// A launcher can return before the terminal starts the program.
func TestThePruneKeepsTheVersionsOfANewRecordWithNoProcess(t *testing.T) {
	r, dir := packageStore(t)
	old := filepath.Join(dir, "skills", "loupe", "1.0.0")
	replaceLoupe(t, dir, "2.0.0", "{}")
	if err := skills.WriteRecord(dir, "s", []skills.Pick{{Package: "loupe", Version: "1.0.0"}}); err != nil {
		t.Fatal(err)
	}

	r.pruneSkills(time.Now())
	if !exists(old) {
		t.Fatal("the version of a session that is still starting was deleted")
	}
	ageRecord(t, dir, "s", launchGrace)
	r.pruneSkills(time.Now())
	if exists(old) {
		t.Fatal("the version stayed after the grace ended")
	}
}

// With no process list, the prune cannot tell which session runs.
func TestAPruneThatCannotListTheProcessesDeletesNothing(t *testing.T) {
	for name, list := range map[string]func(context.Context) (string, error){
		"failed": func(context.Context) (string, error) { return "", errors.New("ps failed") },
		"empty":  func(context.Context) (string, error) { return " \n", nil },
	} {
		r, dir := packageStore(t)
		old := filepath.Join(dir, "skills", "loupe", "1.0.0")
		replaceLoupe(t, dir, "2.0.0", "{}")
		if err := skills.WriteRecord(dir, "s", []skills.Pick{{Package: "loupe", Version: "1.0.0"}}); err != nil {
			t.Fatal(err)
		}
		ageRecord(t, dir, "s", 2*skills.KeepAfterReplace)
		r.skills.processes = list

		r.pruneSkills(time.Now())
		if !exists(old) || skills.ReadRecord(dir, "s") == nil {
			t.Fatalf("%s: the prune deleted the version or the record", name)
		}
	}
}

func TestThePruneKeepsTheVersionsOfARunThatStartedThroughTheRouter(t *testing.T) {
	h := newHarness(t)
	store := t.TempDir()
	old := filepath.Join(store, "skills", "loupe", "1.0.0")
	if err := os.MkdirAll(old, 0o755); err != nil {
		t.Fatal(err)
	}
	h.router.skills.dir = store
	h.router.skills.exe = func() (string, error) { return "/bin/loupe", nil }
	h.router.skills.processes = noSessions
	started, release := make(chan workerSpec), make(chan struct{})
	h.router.worker.run = func(_ context.Context, spec workerSpec, onStart func(workerProc)) workerResult {
		onStart(workerProc{})
		started <- spec
		<-release

		return workerResult{hasResult: true}
	}

	h.router.onData([]byte(cardMoved(87)))
	spec := <-started
	if len(spec.packages) != 1 || spec.packages[0].Version != "1.0.0" {
		t.Fatalf("packages = %+v", spec.packages)
	}
	replaceLoupe(t, store, "2.0.0", "{}")
	// The hold alone must keep the version while the worker runs.
	if err := os.RemoveAll(filepath.Join(store, "skills", ".records")); err != nil {
		t.Fatal(err)
	}
	h.router.pruneSkills(time.Now())
	if !exists(old) {
		t.Fatal("the version of a running worker was deleted")
	}
	close(release)
	eventually(t, "the end of the hold", func() bool {
		h.router.skills.mu.Lock()
		defer h.router.skills.mu.Unlock()

		return len(h.router.skills.holds) == 0
	})
	eventually(t, "the end of the run", func() bool {
		h.router.pruneSkills(time.Now())

		return !exists(old)
	})
}

// The bridge removes the run folder when a run ends, and a resume reads the
// record after that.
func TestARecordOutlivesItsRun(t *testing.T) {
	h := newHarness(t)
	store := t.TempDir()
	if err := os.MkdirAll(filepath.Join(store, "skills", "loupe", "1.0.0"), 0o755); err != nil {
		t.Fatal(err)
	}
	h.router.skills.dir = store
	h.router.skills.exe = func() (string, error) { return "/bin/loupe", nil }
	runDir := t.TempDir()
	sessions := make(chan string, 1)
	h.router.worker.run = func(_ context.Context, spec workerSpec, onStart func(workerProc)) workerResult {
		onStart(workerProc{dir: runDir})
		sessions <- spec.sessionID

		return workerResult{hasResult: true, dir: runDir}
	}

	h.router.onData([]byte(cardMoved(87)))
	session := <-sessions
	eventually(t, "the removal of the run folder", func() bool { return !exists(runDir) })
	if got := skills.ReadRecord(store, session); got["loupe"] != "1.0.0" {
		t.Fatalf("record = %v, want loupe 1.0.0", got)
	}
}

// A version stays current while a higher one cannot run, so its age says
// nothing about whether anything needs it.
func TestThePruneKeepsTheVersionANewWorkerWouldGet(t *testing.T) {
	r, dir := packageStore(t)
	r.skills.cliVersion = "1.0.0"
	replaceLoupe(t, dir, "2.0.0", `{"minCli":"9.0.0"}`)

	r.pruneSkills(time.Now())
	if !exists(filepath.Join(dir, "skills", "loupe", "1.0.0")) {
		t.Fatal("the only version this CLI can run was deleted")
	}

	r.skills.cliVersion = "9.0.0"
	r.pruneSkills(time.Now())
	if exists(filepath.Join(dir, "skills", "loupe", "1.0.0")) {
		t.Fatal("a replaced version stayed")
	}
}

func TestThePruneKeepsTheVersionARequiresHoldsDown(t *testing.T) {
	r, dir := packageStore(t, api.Site{ID: "p1", Slug: "life", SkillPackages: []string{"loupe-lifecycle"}})
	if err := os.WriteFile(filepath.Join(dir, "skills", "loupe-lifecycle", "1.0.0", "loupe-package.json"), []byte(`{"requires":{"loupe":"<2.0.0"}}`), 0o644); err != nil {
		t.Fatal(err)
	}
	replaceLoupe(t, dir, "2.0.0", "{}")

	r.pruneSkills(time.Now())
	if !exists(filepath.Join(dir, "skills", "loupe", "1.0.0")) {
		t.Fatal("the version that loupe-lifecycle needs was deleted")
	}
}
