package cmd

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/spf13/cobra"

	"github.com/loupe-ac/loupe/cli/internal/api"
	harn "github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/outbound"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

var loginTimedOut = harn.Problem{Reason: "login check timed out", Detail: "claude auth status did not answer", Temporary: true}

var loggedOut = harn.Problem{Reason: "not logged in", Detail: "run `claude auth login`"}

func claudeResult(problems ...harn.Problem) []accountResult {
	return []accountResult{{name: "claude", harness: "claude-code", used: true, problems: problems}}
}

// startAccounts puts the router in the state the start of a bridge leaves.
func (h *harness) startAccounts(results []accountResult) {
	set := h.router.rules()
	set.SetAccountProblems(accountsOff(results))
	set.SetProjectProblems(projectsOff(results))
	warnAccountsOff(h.router.log, results)
}

// fakeRecheck answers the rechecks in turn, and the last answer repeats. during
// runs inside each check.
type fakeRecheck struct {
	mu      sync.Mutex
	answers [][]accountResult
	asked   [][]string
	during  func()
}

func (f *fakeRecheck) check(_ context.Context, _ *rules.Set, names []string) []accountResult {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.asked = append(f.asked, names)
	if f.during != nil {
		f.during()
	}
	answer := f.answers[0]
	if len(f.answers) > 1 {
		f.answers = f.answers[1:]
	}

	return answer
}

func (f *fakeRecheck) calls() [][]string {
	f.mu.Lock()
	defer f.mu.Unlock()

	return slices.Clone(f.asked)
}

func (h *harness) fakeRecheck(answers ...[]accountResult) *fakeRecheck {
	f := &fakeRecheck{answers: answers}
	h.router.recheck = f.check

	return f
}

func (h *harness) wantEvents(t *testing.T, want map[string]int) {
	t.Helper()
	for _, event := range []string{"account_failed", "project_failed", "account_recovered", "project_recovered"} {
		if got := h.events(t, event); len(got) != want[event] {
			t.Fatalf("%d %s lines, want %d: %v", len(got), event, want[event], got)
		}
	}
}

// lastAccounts is the account rows of the newest heartbeat sent.
func lastAccounts(client *fakeHeartbeats) []api.AccountReport {
	client.mu.Lock()
	defer client.mu.Unlock()
	if len(client.sent) == 0 {
		return nil
	}

	return client.sent[len(client.sent)-1].Accounts
}

func TestTheRecheckLoopHealsAnAccountWhoseTimeoutClears(t *testing.T) {
	h := newHarness(t)
	h.startAccounts(claudeResult(loginTimedOut))
	h.router.recheckSoon = anyTemporary(claudeResult(loginTimedOut))
	client := &fakeHeartbeats{}
	h.router.heartbeat = startHeartbeater(t, client, time.Minute).h
	timers := &fakeTimers{}
	h.router.recheckAfter = timers.after
	checks := h.fakeRecheck(claudeResult(loginTimedOut), claudeResult())

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { defer close(done); h.router.recheckLoop(ctx) }()
	defer func() { cancel(); <-done }()

	// fire ends the n-th wait after a check of its length.
	fire := func(n int, want time.Duration) {
		t.Helper()
		eventually(t, "the next wait", func() bool { return timers.count() == n })
		delay, ch := timers.last()
		if delay != want {
			t.Fatalf("wait %d = %s, want %s", n, delay, want)
		}
		ch <- time.Now()
		eventually(t, "the end of the tick", func() bool { return timers.count() == n+1 })
	}

	fire(1, 30*time.Second)
	h.wantEvents(t, map[string]int{"account_failed": 1})
	if m := h.router.rules().MatchWork(workRequest(1, 7, "plan", api.WorkRequestOpen)); m.Skip != rules.NoRule {
		t.Fatalf("match while the check times out = %+v", m)
	}

	fire(2, 5*time.Minute)
	h.wantEvents(t, map[string]int{"account_failed": 1, "account_recovered": 1})
	line := h.only(t, "account_recovered")
	if str(t, line, "account") != "claude" || str(t, line, "harness") != "claude-code" || line["unused"] != false {
		t.Fatalf("account_recovered = %v", line)
	}
	if m := h.router.rules().MatchWork(workRequest(2, 8, "plan", api.WorkRequestOpen)); m.Skip != rules.Run {
		t.Fatalf("match after the heal = %+v", m)
	}
	eventually(t, "the heartbeat with the ready account", func() bool {
		rows := lastAccounts(client)

		return len(rows) == 1 && rows[0].State == api.AccountReady && rows[0].Reason == ""
	})

	// A bridge with nothing failing checks nothing.
	fire(3, 5*time.Minute)
	if asked := checks.calls(); len(asked) != 2 {
		t.Fatalf("checks = %v, want 2", asked)
	}
}

func TestTheFirstRecheckComesSoonOnlyAfterATimeout(t *testing.T) {
	temporaryProject := loginTimedOut
	temporaryProject.Project = "loupe"
	for _, tc := range []struct {
		name    string
		results []accountResult
		want    time.Duration
	}{
		{"no problem", claudeResult(), 5 * time.Minute},
		{"a real fault", claudeResult(loggedOut), 5 * time.Minute},
		{"a timeout", claudeResult(loggedOut, loginTimedOut), 30 * time.Second},
		{"a timeout of one project", claudeResult(temporaryProject), 30 * time.Second},
	} {
		t.Run(tc.name, func(t *testing.T) {
			h := newHarness(t)
			h.router.recheckSoon = anyTemporary(tc.results)
			timers := &fakeTimers{}
			h.router.recheckAfter = timers.after
			ctx, cancel := context.WithCancel(context.Background())
			done := make(chan struct{})
			go func() { defer close(done); h.router.recheckLoop(ctx) }()
			eventually(t, "the first wait", func() bool { return timers.count() == 1 })
			cancel()
			<-done
			if delay, _ := timers.last(); delay != tc.want {
				t.Fatalf("first wait = %s, want %s", delay, tc.want)
			}
		})
	}
}

func TestARealFaultLogsOnce(t *testing.T) {
	h := newHarness(t)
	project := harn.Problem{Reason: "claude is not on PATH", Project: "loupe", Detail: "no claude"}
	h.startAccounts(claudeResult(loggedOut, project))
	h.fakeRecheck(claudeResult(loggedOut, project))

	for range 2 {
		if h.router.recheckAccounts(context.Background()) {
			t.Fatal("a recheck that found the same faults reports a change")
		}
	}

	h.wantEvents(t, map[string]int{"account_failed": 1, "project_failed": 1})
}

func TestAChangedReasonLogsOnce(t *testing.T) {
	h := newHarness(t)
	h.startAccounts(claudeResult(loginTimedOut))
	h.fakeRecheck(claudeResult(loggedOut))

	changed := []bool{h.router.recheckAccounts(context.Background()), h.router.recheckAccounts(context.Background())}

	if !slices.Equal(changed, []bool{true, false}) {
		t.Fatalf("changed = %v", changed)
	}
	h.wantEvents(t, map[string]int{"account_failed": 2})
	line := h.events(t, "account_failed")[1]
	for key, want := range map[string]string{"level": "WARN", "account": "claude", "harness": "claude-code", "reason": "not logged in", "detail": "run `claude auth login`"} {
		if got := str(t, line, key); got != want {
			t.Fatalf("account_failed %s = %q, want %q", key, got, want)
		}
	}
	if got := h.router.rules().AccountsOff()["claude"]; got != "not logged in" {
		t.Fatalf("reason = %q", got)
	}
}

// A healed account can still fail a project, and a failed project heals alone.
func TestARecheckLogsEachPartThatChanges(t *testing.T) {
	h := newHarness(t)
	project := harn.Problem{Reason: "claude is not on PATH", Project: "loupe", Detail: "no claude"}
	h.startAccounts(claudeResult(loginTimedOut))
	h.fakeRecheck(claudeResult(project), claudeResult())

	h.router.recheckAccounts(context.Background())
	h.wantEvents(t, map[string]int{"account_failed": 1, "account_recovered": 1, "project_failed": 1})
	line := h.only(t, "project_failed")
	if str(t, line, "project") != "loupe" || str(t, line, "reason") != project.Reason || str(t, line, "detail") != project.Detail {
		t.Fatalf("project_failed = %v", line)
	}

	h.router.recheckAccounts(context.Background())
	h.wantEvents(t, map[string]int{"account_failed": 1, "account_recovered": 1, "project_failed": 1, "project_recovered": 1})
	line = h.only(t, "project_recovered")
	if str(t, line, "project") != "loupe" || str(t, line, "account") != "claude" || str(t, line, "harness") != "claude-code" {
		t.Fatalf("project_recovered = %v", line)
	}
	if off := h.router.rules().ProjectsOff(); len(off) != 0 {
		t.Fatalf("ProjectsOff = %v", off)
	}
}

// A result the recheck does not apply logs nothing.
func TestARecheckLogsNoDiscardedProblem(t *testing.T) {
	h := newHarness(t)
	old := harn.Problem{Reason: "claude is not on PATH", Project: "loupe"}
	h.startAccounts(claudeResult(old))
	h.fakeRecheck(claudeResult(loggedOut))

	if h.router.recheckAccounts(context.Background()) {
		t.Fatal("the recheck changed the state")
	}

	h.wantEvents(t, map[string]int{"project_failed": 1})
}

func TestARecheckChecksOnlyTheFailingAccounts(t *testing.T) {
	body := "accounts:\n  a:\n    harness: claude-code\n  b:\n    harness: claude-code\n  c:\n    harness: claude-code\n  spare:\n    harness: claude-code\ndefaults:\n  account: a\nprojects:\n  loupe:\n    dir: {dir}\nwork:\n  plan:\n    prompt: go\n"
	h := newHarnessWith(t, body, rules.Defaults{})
	checks := h.fakeRecheck(nil)
	h.router.rules().SetAccountProblems(map[string]string{})

	h.router.recheckAccounts(context.Background())
	if asked := checks.calls(); len(asked) != 0 {
		t.Fatalf("a ready bridge checked %v", asked)
	}

	h.router.rules().SetAccountProblems(map[string]string{"spare": "not logged in", "b": "not logged in"})
	h.router.rules().SetProjectProblems(map[string]map[string]string{"c": {"loupe": "x"}, "b": {"loupe": "x"}})
	h.router.recheckAccounts(context.Background())
	if asked := checks.calls(); len(asked) != 1 || !slices.Equal(asked[0], []string{"b", "c", "spare"}) {
		t.Fatalf("checks = %v", asked)
	}
}

func TestARecheckDropsAResultThatCameTooLate(t *testing.T) {
	for _, tc := range []struct {
		name   string
		during func(t *testing.T, h *harness, cancel context.CancelFunc)
	}{
		{"the set changed", func(t *testing.T, h *harness, _ context.CancelFunc) {
			next, _ := loadRules(t, defaultRules, rules.Defaults{})
			next.SetAccountProblems(map[string]string{"claude": "login check timed out"})
			h.router.set.Store(next)
		}},
		{"a reload runs", func(_ *testing.T, h *harness, _ context.CancelFunc) {
			h.router.mu.Lock()
			h.router.reloading = true
			h.router.mu.Unlock()
		}},
		{"the context ended", func(_ *testing.T, _ *harness, cancel context.CancelFunc) { cancel() }},
		{"the router shut down", func(_ *testing.T, h *harness, _ context.CancelFunc) { h.router.shutdown() }},
	} {
		t.Run(tc.name, func(t *testing.T) {
			h := newHarness(t)
			h.startAccounts(claudeResult(loginTimedOut))
			old := h.router.rules()
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			for _, answer := range [][]accountResult{claudeResult(), claudeResult(loggedOut)} {
				f := h.fakeRecheck(answer)
				f.during = func() { tc.during(t, h, cancel) }
				h.router.set.Store(old)

				if h.router.recheckAccounts(ctx) {
					t.Fatal("the late result changed the state")
				}

				for _, set := range []*rules.Set{old, h.router.rules()} {
					if got := set.AccountsOff()["claude"]; got != "login check timed out" {
						t.Fatalf("reason = %q", got)
					}
				}
			}
			h.wantEvents(t, map[string]int{"account_failed": 1})
		})
	}
}

// A reload does not wait for a recheck.
func TestARecheckHoldsNoReloadLock(t *testing.T) {
	h := newHarness(t)
	h.startAccounts(claudeResult(loginTimedOut))
	f := h.fakeRecheck(claudeResult())
	free := false
	f.during = func() {
		if free = h.router.reloadMu.TryLock(); free {
			h.router.reloadMu.Unlock()
		}
	}

	h.router.recheckAccounts(context.Background())

	if !free {
		t.Fatal("the recheck held reloadMu")
	}
}

// The reasons of a reload are the ones a recheck compares with.
func TestARecheckAfterAReloadLogsNoKnownReason(t *testing.T) {
	h := newHarness(t)
	h.startAccounts(claudeResult(loginTimedOut))
	src := h.source(defaultRules)
	src.checkAccounts = func(context.Context, *rules.Set) []accountResult { return claudeResult(loggedOut) }
	if res := h.router.reload(context.Background(), src); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.fakeRecheck(claudeResult(loggedOut))

	h.router.recheckAccounts(context.Background())

	h.wantEvents(t, map[string]int{"account_failed": 2})
}

// hookQueue runs before on the next heartbeat it takes.
type hookQueue struct {
	outbound.Queue
	before func()
	sent   int
}

func (q *hookQueue) SendLatest(string, func(context.Context) error, func(error)) {
	q.sent++
	if before := q.before; before != nil {
		q.before = nil
		before()
	}
}

// A reload can swap the set while a recheck sends its body, and that body must
// not stay the last one.
func TestAStaleHeartbeatBodyNeverWins(t *testing.T) {
	h := newHarness(t)
	h.startAccounts(claudeResult(loginTimedOut))
	h.fakeRecheck(claudeResult())
	next, _ := loadRules(t, defaultRules, rules.Defaults{})
	next.SetAccountProblems(map[string]string{"claude": "not logged in"})
	queue := &hookQueue{}
	hb := newHeartbeater(context.Background(), queue, &fakeHeartbeats{}, testBridgeID, api.Heartbeat{}, time.Minute, h.router.log)
	// The swap sent its body first, so the body of the recheck is the last one.
	queue.before = func() { h.router.set.Store(next) }
	h.router.heartbeat = hb

	h.router.recheckAccounts(context.Background())

	hb.mu.Lock()
	defer hb.mu.Unlock()
	if rows := hb.body.Accounts; len(rows) != 1 || rows[0].Reason != "not logged in" {
		t.Fatalf("the last body has rows %+v after %d sends", rows, queue.sent)
	}
}

// The real check heals an account whose first login check ran out of time.
func TestARecheckHealsARealLoginCheckThatTimedOut(t *testing.T) {
	loggedInClaude(t)
	bin := t.TempDir()
	slow := filepath.Join(bin, "slow")
	if err := os.WriteFile(slow, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	body := "#!/bin/sh\n[ -f \"" + slow + "\" ] && exec sleep 30\nexit 0\n"
	if err := os.WriteFile(filepath.Join(bin, "claude"), []byte(body), 0o700); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin+string(os.PathListSeparator)+os.Getenv("PATH"))
	h := newHarness(t)
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	start := checkAccounts(ctx, h.router.rules())
	if start[0].reason() != "login check timed out" || !anyTemporary(start) {
		t.Fatalf("start check = %+v", start)
	}
	h.startAccounts(start)
	if err := os.Remove(slow); err != nil {
		t.Fatal(err)
	}

	if !h.router.recheckAccounts(context.Background()) {
		t.Fatalf("the recheck changed nothing: %s", h.log.String())
	}

	if rows := accountReports(h.router.rules()); len(rows) != 1 || rows[0].State != api.AccountReady {
		t.Fatalf("rows = %+v", rows)
	}
	if !strings.Contains(h.log.String(), `"event":"account_recovered"`) {
		t.Fatalf("log = %s", h.log.String())
	}
}

func TestSubscribeStartsTheRecheckLoop(t *testing.T) {
	injectVersion(t, "1.0.0")
	fake := &fakeLoupe{flags: `{"bridge.heartbeat_interval_seconds":3600}`}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)
	set, _ := loadRules(t, defaultRules, rules.Defaults{})
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}
	timers := &fakeTimers{}
	r := withRules(&router{log: newBridgeLogger(&syncBuffer{}), worker: (&fakeWorker{}).ops(), bridgeID: testBridgeID, recheckSoon: true, recheckAfter: timers.after}, set)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()

	eventually(t, "the first wait of the recheck", func() bool { return timers.count() == 1 })
	if delay, _ := timers.last(); delay != 30*time.Second {
		t.Fatalf("first wait = %s", delay)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}
