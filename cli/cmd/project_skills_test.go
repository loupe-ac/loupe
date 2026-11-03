package cmd

import (
	"bytes"
	"context"
	"errors"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
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

func TestTheSkillLoopReadsAtOnceAndAgainEachInterval(t *testing.T) {
	reads := make(chan struct{}, 4)
	r, _ := skillRouter(func(context.Context) ([]api.Site, error) {
		reads <- struct{}{}

		return nil, nil
	})
	tick := make(chan time.Time)
	var waited time.Duration
	r.skills.after = func(d time.Duration) <-chan time.Time {
		waited = d

		return tick
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { r.skillLoop(ctx); close(done) }()

	<-reads
	tick <- time.Now()
	<-reads
	cancel()
	<-done
	if waited != time.Hour {
		t.Fatalf("waited = %v", waited)
	}
}

func TestARouterWithNoListReadsNothing(t *testing.T) {
	r, _ := skillRouter(nil)
	r.loadSkills(context.Background())

	if _, listed := r.projectSite("any"); listed {
		t.Fatal("a router with no list knows a project")
	}
}
