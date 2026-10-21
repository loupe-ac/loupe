package cmd

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
)

func quietLog() *slog.Logger { return slog.New(slog.NewTextHandler(io.Discard, nil)) }

func TestParseGitRemoteClassifiesPushAddresses(t *testing.T) {
	tests := []struct {
		raw  string
		want gitRemote
	}{
		{"https://github.com/acme/app.git", gitRemote{kind: remoteHTTPS, host: "github.com", path: "acme/app.git"}},
		{"https://user:pw@github.com/acme/app.git", gitRemote{kind: remoteHTTPS, host: "github.com", path: "acme/app.git", username: "user", password: "pw"}},
		{"https://user@github.com/acme/app.git", gitRemote{kind: remoteHTTPS, host: "github.com", path: "acme/app.git", username: "user"}},
		{"https://us%0Aer@github.com/acme/app.git", gitRemote{}},
		{"https://github.com/acme/a%0Apassword=x", gitRemote{}},
		{"https://github.com:8443/acme/app.git", gitRemote{}},
		{"https://gitlab.com/acme/app.git", gitRemote{}},
		{"git@github.com:acme/app.git", gitRemote{kind: remoteSSH, host: "github.com"}},
		{"ssh://git@github.com/acme/app.git", gitRemote{kind: remoteSSH, host: "github.com"}},
		{"ssh://git@ssh.github.com:443/acme/app.git", gitRemote{kind: remoteSSH, host: "ssh.github.com", port: "443"}},
		{"git@gitlab.com:acme/app.git", gitRemote{}},
		{"/srv/git/app.git", gitRemote{}},
		{"", gitRemote{}},
	}
	for _, tt := range tests {
		if got := parseGitRemote(tt.raw); got != tt.want {
			t.Errorf("parseGitRemote(%q) = %+v, want %+v", tt.raw, got, tt.want)
		}
	}
}

func targetOne(dir string) []identityTarget {
	return []identityTarget{{id: "p1", slug: "loupe", dir: dir}}
}

func machineProbes(pushURL string) identityProbes {
	return identityProbes{
		commitName: func(context.Context, string) string { return "  Ada Lovelace \n" },
		pushURL:    func(context.Context, string) string { return pushURL },
		credential: func(context.Context, string, gitRemote) string { return "" },
		user:       func(context.Context, string) (string, int64, error) { return "", 0, io.EOF },
		ssh:        func(context.Context, gitRemote) string { return "" },
	}
}

func newTestReporter(agent *config.AgentAccount, login string, probes identityProbes) *identityReporter {
	r := &identityReporter{ctx: context.Background(), log: quietLog(), agent: agent, login: login, probes: probes, now: time.Now}
	if agent != nil && login != "" {
		r.agentID = agent.ID
	}

	return r
}

func TestARefreshRechecksTheAgentAccount(t *testing.T) {
	var refused atomic.Bool
	probes := machineProbes("")
	probes.user = func(_ context.Context, token string) (string, int64, error) {
		if token != "tok" {
			t.Errorf("token = %q", token)
		}
		if refused.Load() {
			return "", 0, io.EOF
		}

		return "bot", 42, nil
	}
	r := newTestReporter(&config.AgentAccount{Login: "bot", ID: 42, Token: "tok"}, "bot", probes)
	targets := targetOne("/x")

	refused.Store(true)
	r.refresh(context.Background(), targets)
	if got, _ := r.rows(targets); len(got) != 1 || got[0].Mode != api.GitModeAgentAccount || got[0].PushLogin != "" || got[0].GitHubUserID != nil {
		t.Fatalf("a refused token gave %+v", got)
	}

	refused.Store(false)
	r.refresh(context.Background(), targets)
	if got, _ := r.rows(targets); len(got) != 1 || got[0].PushLogin != "bot" || got[0].GitHubUserID == nil || *got[0].GitHubUserID != 42 {
		t.Fatalf("an accepted token gave %+v", got)
	}
}

func TestIdentityModeSelection(t *testing.T) {
	ctx := context.Background()
	managed := func(context.Context, string, string) bool { return true }

	t.Run("agent account wins", func(t *testing.T) {
		r := newTestReporter(&config.AgentAccount{Login: "bot", ID: 42}, "bot", machineProbes(""))
		r.managed = managed
		row := r.identity(ctx, targetOne("/x")[0])
		if row.Mode != api.GitModeAgentAccount || row.CommitName != "bot" || row.PushLogin != "bot" || row.GitHubUserID == nil || *row.GitHubUserID != 42 {
			t.Fatalf("row = %+v", row)
		}
	})
	t.Run("agent account whose check failed", func(t *testing.T) {
		r := newTestReporter(&config.AgentAccount{Login: "bot", ID: 42}, "", machineProbes(""))
		row := r.identity(ctx, targetOne("/x")[0])
		if row.Mode != api.GitModeAgentAccount || row.PushLogin != "" || row.GitHubUserID != nil {
			t.Fatalf("row = %+v", row)
		}
	})
	t.Run("managed project", func(t *testing.T) {
		r := newTestReporter(nil, "", machineProbes(""))
		r.managed = managed
		row := r.identity(ctx, targetOne("/x")[0])
		if row.Mode != api.GitModeAppToken || row.CommitName != "" || row.PushLogin != "" || row.GitHubUserID != nil {
			t.Fatalf("row = %+v", row)
		}
	})
	t.Run("machine", func(t *testing.T) {
		r := newTestReporter(nil, "", machineProbes(""))
		r.managed = func(context.Context, string, string) bool { return false }
		row := r.identity(ctx, targetOne("/x")[0])
		if row.Mode != api.GitModeMachine || row.CommitName != "Ada Lovelace" || row.PushLogin != "" || row.GitHubUserID != nil {
			t.Fatalf("row = %+v", row)
		}
	})
}

func TestMachineIdentityFromAnSSHGreeting(t *testing.T) {
	probes := machineProbes("git@github.com:acme/app.git")
	probes.ssh = func(_ context.Context, r gitRemote) string {
		if r.host != "github.com" {
			t.Errorf("host = %q", r.host)
		}

		return "Hi octo-cat! You've successfully authenticated, but GitHub does not provide shell access.\n"
	}
	row := newTestReporter(nil, "", probes).identity(context.Background(), targetOne("/x")[0])
	if row.PushLogin != "octo-cat" || row.GitHubUserID != nil {
		t.Fatalf("row = %+v", row)
	}

	probes.ssh = func(context.Context, gitRemote) string { return "git@github.com: Permission denied (publickey).\n" }
	if row := newTestReporter(nil, "", probes).identity(context.Background(), targetOne("/x")[0]); row.PushLogin != "" {
		t.Fatalf("no greeting gave %+v", row)
	}
}

func TestMachineIdentityFromAnHTTPSTokenNeverLeaksTheToken(t *testing.T) {
	const token = "ghp_secret_token_value"
	probes := machineProbes("https://github.com/acme/app.git")
	probes.credential = func(_ context.Context, _ string, r gitRemote) string {
		if r.host != "github.com" || r.path != "acme/app.git" {
			t.Errorf("remote = %+v", r)
		}

		return token
	}
	probes.user = func(_ context.Context, got string) (string, int64, error) {
		if got != token {
			t.Errorf("token = %q", got)
		}

		return "octocat", 583231, nil
	}
	row := newTestReporter(nil, "", probes).identity(context.Background(), targetOne("/x")[0])
	if row.PushLogin != "octocat" || row.GitHubUserID == nil || *row.GitHubUserID != 583231 {
		t.Fatalf("row = %+v", row)
	}
	body, err := json.Marshal(api.Heartbeat{GitIdentities: []api.GitIdentityReport{row}})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(body), token) {
		t.Fatalf("body leaks the token: %s", body)
	}
}

func TestMachineIdentityRefusesAnUnusableLogin(t *testing.T) {
	probes := machineProbes("https://github.com/acme/app.git")
	probes.credential = func(context.Context, string, gitRemote) string { return "t" }
	probes.user = func(context.Context, string) (string, int64, error) { return "bad login;rm", 1, nil }
	if row := newTestReporter(nil, "", probes).identity(context.Background(), targetOne("/x")[0]); row.PushLogin != "" || row.GitHubUserID != nil {
		t.Fatalf("row = %+v", row)
	}
}

func TestCommitNameIsCapped(t *testing.T) {
	probes := machineProbes("")
	probes.commitName = func(context.Context, string) string { return strings.Repeat("é", 400) }
	row := newTestReporter(nil, "", probes).identity(context.Background(), targetOne("/x")[0])
	if n := len([]rune(row.CommitName)); n != maxCommitName {
		t.Fatalf("commit name has %d runes", n)
	}
}

func TestTheHeartbeatShapeOfGitIdentities(t *testing.T) {
	id := int64(7)
	rows := []api.GitIdentityReport{
		{ProjectID: "p1", Mode: api.GitModeAgentAccount, CommitName: "bot", PushLogin: "bot", GitHubUserID: &id},
		{ProjectID: "p2", Mode: api.GitModeMachine},
	}
	b, err := json.Marshal(api.Heartbeat{GitIdentities: rows, GitIdentitiesAge: 540})
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		`"gitIdentitiesAge":540`,
		`"gitIdentities":[{"projectId":"p1","mode":"agent_account","commitName":"bot","pushLogin":"bot","githubUserId":7},`,
		`{"projectId":"p2","mode":"machine","commitName":"","pushLogin":"","githubUserId":null}]`,
	} {
		if !strings.Contains(string(b), want) {
			t.Fatalf("body %s lacks %s", b, want)
		}
	}
	if b, _ := json.Marshal(api.Heartbeat{}); strings.Contains(string(b), "gitIdentities") || strings.Contains(string(b), "pushLogin") || strings.Contains(string(b), "gitIdentitiesAge") {
		t.Fatalf("empty body %s", b)
	}
	if b, _ := json.Marshal(api.Heartbeat{GitIdentities: []api.GitIdentityReport{}}); !strings.Contains(string(b), `"gitIdentities":[]`) {
		t.Fatalf("empty list body %s", b)
	}
}

func TestIdentityRowsAreNilUntilTheFirstRead(t *testing.T) {
	r := newTestReporter(nil, "", machineProbes(""))
	if got, _ := r.rows(targetOne("/x")); got != nil {
		t.Fatalf("rows = %+v", got)
	}
	var none *identityReporter
	if got, _ := none.rows(targetOne("/x")); got != nil || none.start(context.Background(), nil) != nil {
		t.Fatal("a nil reporter reports rows")
	}
}

func TestIdentityRowsFollowTheCurrentProjects(t *testing.T) {
	r := newTestReporter(nil, "", machineProbes(""))
	both := []identityTarget{{id: "p1", slug: "a", dir: "/a"}, {id: "p2", slug: "b", dir: "/b"}}
	r.refresh(context.Background(), both)
	if got, _ := r.rows(both); len(got) != 2 {
		t.Fatalf("rows = %+v", got)
	}
	if got, _ := r.rows(both[1:]); len(got) != 1 || got[0].ProjectID != "p2" {
		t.Fatalf("rows after a project left = %+v", got)
	}
	if got, _ := r.rows(nil); got == nil || len(got) != 0 {
		t.Fatalf("no projects gave %#v, want an empty list", got)
	}
}

func TestAStaleCacheStartsOneBackgroundRefresh(t *testing.T) {
	var calls atomic.Int32
	release := make(chan struct{})
	probes := machineProbes("")
	probes.commitName = func(context.Context, string) string {
		if calls.Add(1) > 1 {
			<-release
		}

		return "Ada"
	}
	var mu sync.Mutex
	clock := time.Now()
	r := newTestReporter(nil, "", probes)
	r.now = func() time.Time { mu.Lock(); defer mu.Unlock(); return clock }
	targets := targetOne("/x")
	r.refresh(context.Background(), targets)

	// Fresh: no probe.
	r.rows(targets)
	if calls.Load() != 1 {
		t.Fatalf("a fresh cache probed: %d calls", calls.Load())
	}

	mu.Lock()
	clock = clock.Add(identityTTL + time.Second)
	mu.Unlock()
	for range 3 {
		if got, _ := r.rows(targets); len(got) != 1 || got[0].CommitName != "Ada" {
			t.Fatalf("a stale cache gave %+v", got)
		}
	}
	deadline := time.Now().Add(2 * time.Second)
	for calls.Load() < 2 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if calls.Load() != 2 {
		t.Fatalf("calls = %d, want one background refresh", calls.Load())
	}
	close(release)
	deadline = time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		r.mu.Lock()
		idle := r.inflight == 0
		r.mu.Unlock()
		if idle {
			break
		}
		time.Sleep(time.Millisecond)
	}
	r.rows(targets)
	if calls.Load() != 2 {
		t.Fatalf("calls = %d after the refresh, want 2", calls.Load())
	}
}

func TestMachineIdentityUsesTheTokenOfThePushAddress(t *testing.T) {
	probes := machineProbes("https://owner:url_token@github.com/acme/app.git")
	probes.credential = func(context.Context, string, gitRemote) string {
		t.Error("asked a helper for an address that holds a token")

		return "helper_token"
	}
	probes.user = func(_ context.Context, token string) (string, int64, error) {
		if token == "url_token" {
			return "owner", 1, nil
		}

		return "agent-bot", 2, nil
	}
	if row := newTestReporter(nil, "", probes).identity(context.Background(), targetOne("/x")[0]); row.PushLogin != "owner" {
		t.Fatalf("row = %+v", row)
	}
}

func TestMachineIdentityAsksTheHelperForTheUserOfThePushAddress(t *testing.T) {
	var question atomic.Value
	probes := machineProbes("https://owner@github.com/acme/app.git")
	probes.credential = func(_ context.Context, _ string, r gitRemote) string {
		question.Store(r.username)

		return "t"
	}
	probes.user = func(context.Context, string) (string, int64, error) { return "owner", 1, nil }
	newTestReporter(nil, "", probes).identity(context.Background(), targetOne("/x")[0])
	if got, _ := question.Load().(string); got != "owner" {
		t.Fatalf("the helper got username %q", got)
	}
}

func TestARefreshReadsEveryFollowedProject(t *testing.T) {
	if api.MaxGitIdentities < api.MaxRepositories {
		t.Fatalf("a heartbeat carries %d identities for %d followed projects", api.MaxGitIdentities, api.MaxRepositories)
	}
	var lookups atomic.Int32
	probes := machineProbes("https://github.com/acme/app.git")
	probes.credential = func(context.Context, string, gitRemote) string { return "shared" }
	probes.user = func(context.Context, string) (string, int64, error) {
		lookups.Add(1)

		return "octocat", 1, nil
	}
	targets := make([]identityTarget, 51)
	for i := range targets {
		targets[i] = identityTarget{id: fmt.Sprintf("p%d", i), slug: fmt.Sprintf("s%d", i), dir: "/x"}
	}
	r := newTestReporter(nil, "", probes)
	r.refresh(context.Background(), targets)
	got, _ := r.rows(targets)
	if len(got) != 51 || got[50].PushLogin != "octocat" {
		t.Fatalf("rows = %d, last = %+v", len(got), got[len(got)-1])
	}
	if lookups.Load() != 1 {
		t.Fatalf("one token was looked up %d times", lookups.Load())
	}
}

func TestIdentityRowsCarryTheTimeSinceTheRead(t *testing.T) {
	clock := time.Now()
	r := newTestReporter(nil, "", machineProbes(""))
	r.now = func() time.Time { return clock }
	targets := targetOne("/x")
	r.refresh(context.Background(), targets)
	clock = clock.Add(4 * time.Minute)
	if _, age := r.rows(targets); age != 4*time.Minute {
		t.Fatalf("age after one heartbeat = %v", age)
	}
	clock = clock.Add(5 * time.Minute)
	if _, age := r.rows(targets); age != 9*time.Minute {
		t.Fatalf("age after two heartbeats = %v, want the time since the read", age)
	}
}
