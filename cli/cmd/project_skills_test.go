package cmd

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/spf13/cobra"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/rules"
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
