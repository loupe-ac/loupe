package cmd

import (
	"context"
	"errors"
	"slices"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/loupe-ac/loupe/cli/internal/usage"
)

func blockedClaude() []api.BlockedAccount {
	return []api.BlockedAccount{{Account: "claude", Until: time.Now().Add(time.Hour)}}
}

func limitedReading(window string) *usage.Reading {
	resets := time.Date(2026, 11, 1, 17, 0, 0, 0, time.UTC)
	used := 100.0

	return &usage.Reading{Window: window, UsedPercent: &used, ResetsAt: &resets, Limited: true, Source: usage.SourceClaudeEvent}
}

// A request that matches a blocked account is skipped and reported. The bridge
// claims nothing and queues nothing for it.
func TestAnOfferOfABlockedAccountIsSkippedAndReported(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)
	f.requests[w.WorkRequestID] = w

	h.reply(api.HeartbeatReply{BlockedAccounts: blockedClaude(), WorkRequests: []api.WorkRequest{w}})

	if got := f.claimed(); len(got) != 0 || h.queueLen() != 0 || h.runs() != 0 {
		t.Fatalf("claims = %v, queue = %d, runs = %d", got, h.queueLen(), h.runs())
	}
	want := []api.SkippedWork{{WorkRequestID: workID(1), Account: "claude"}}
	if got := h.router.skips.Pending(); !slices.Equal(got, want) {
		t.Fatalf("skipped = %+v, want %+v", got, want)
	}
}

// The list in the reply is the whole truth. A reply with an empty list frees
// the account, and a reply with no list keeps the old one.
func TestTheBlockedListFollowsTheLatestReplyThatHasOne(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	h.router.personPaused = true
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)
	f.requests[w.WorkRequestID] = w

	h.reply(api.HeartbeatReply{BlockedAccounts: blockedClaude()})
	h.reply(api.HeartbeatReply{WorkRequests: []api.WorkRequest{w}})
	if h.queueLen() != 0 {
		t.Fatal("a reply with no list freed the account")
	}
	h.reply(api.HeartbeatReply{BlockedAccounts: []api.BlockedAccount{}, WorkRequests: []api.WorkRequest{w}})
	if h.queueLen() != 1 {
		t.Fatal("an empty list left the account blocked")
	}
}

// An offer that waits in the queue leaves it when its account becomes blocked.
func TestABlockedAccountDropsTheQueuedOffer(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	h.router.personPaused = true
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)
	f.requests[w.WorkRequestID] = w
	h.reply(api.HeartbeatReply{WorkRequests: []api.WorkRequest{w}})
	if h.queueLen() != 1 {
		t.Fatalf("queue = %d", h.queueLen())
	}

	h.reply(api.HeartbeatReply{BlockedAccounts: blockedClaude(), WorkRequests: []api.WorkRequest{w}})

	if h.queueLen() != 0 || len(h.router.skips.Pending()) != 1 {
		t.Fatalf("queue = %d, skipped = %+v", h.queueLen(), h.router.skips.Pending())
	}
}

// Another account being blocked changes nothing. The claim names the account
// the request matched.
func TestAClaimNamesTheMatchedAccount(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	h.worker.result = workerResult{hasResult: true, status: "finished"}

	h.reply(api.HeartbeatReply{BlockedAccounts: []api.BlockedAccount{{Account: "other"}}})
	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	f.mu.Lock()
	defer f.mu.Unlock()
	if !slices.Equal(f.accounts, []string{"claude"}) {
		t.Fatalf("accounts = %v", f.accounts)
	}
}

const experimentRules = `
accounts:
  claude:
    harness: claude-code
  spare:
    harness: claude-code
defaults:
  account: claude
projects:
  loupe:
    dir: {dir}
work:
  implement:
    prompt: Implement card {cardNumber}.
experiments:
  trial:
    covers: [implement]
    baseline: a
    variants:
      - {name: a, weight: 1, account: claude}
      - {name: b, weight: 1, account: spare}
    metrics: [cost]
    headline:
      goal: {metric: hours-to-merge, better: lower}
      guards: []
`

// The bridge picks one variant, and so one account. It never tries the other.
func TestAnExperimentRequestMatchesOneAccountAndTriesNoOther(t *testing.T) {
	h := newHarnessWith(t, experimentRules, rules.Defaults{})
	f := h.withWork()
	h.router.personPaused = true
	h.reply(api.HeartbeatReply{BlockedAccounts: []api.BlockedAccount{{Account: "claude"}, {Account: "spare"}}})

	for n := 1; n <= 6; n++ {
		w := workRequest(n, 80+n, "implement", api.WorkRequestOpen)
		f.requests[w.WorkRequestID] = w
		h.reply(api.HeartbeatReply{WorkRequests: []api.WorkRequest{w}})
	}
	skipped := h.router.skips.Pending()
	if len(skipped) != 6 || h.queueLen() != 0 {
		t.Fatalf("skipped = %+v, queue = %d", skipped, h.queueLen())
	}
	seen := map[string]bool{}
	for _, s := range skipped {
		seen[s.Account] = true
	}
	if !seen["claude"] || !seen["spare"] {
		t.Fatalf("accounts seen = %v, want both variants to occur", seen)
	}

	// With only the spare blocked, a request that matches claude claims, and
	// one that matches spare skips again with the same account.
	h.reply(api.HeartbeatReply{BlockedAccounts: []api.BlockedAccount{{Account: "spare"}}})
	for _, s := range skipped {
		w := workRequest(1, 0, "implement", api.WorkRequestOpen)
		for n := 1; n <= 6; n++ {
			if workID(n) == s.WorkRequestID {
				w = workRequest(n, 80+n, "implement", api.WorkRequestOpen)
			}
		}
		h.reply(api.HeartbeatReply{WorkRequests: []api.WorkRequest{w}})
		queued := h.queueLen() == 1
		if queued == (s.Account == "spare") {
			t.Fatalf("request %s matched %s and queued = %v", s.WorkRequestID, s.Account, queued)
		}
		h.router.mu.Lock()
		h.router.queue = nil
		h.router.mu.Unlock()
	}
}

// A card pinned to another variant than the one the bridge draws runs on the
// account of the pin. The skip and the claim both name that account.
func TestAPinnedRequestChecksAndClaimsTheAccountOfThePin(t *testing.T) {
	h := newHarnessWith(t, experimentRules, rules.Defaults{})
	f := h.withWork()
	other := map[string]string{"a": "b", "b": "a"}
	pins := h.pins(func(_ context.Context, candidate string) (string, string, error) { return other[candidate], "", nil })
	w := workRequest(1, 81, "implement", api.WorkRequestOpen)
	f.requests[w.WorkRequestID] = w
	exp := h.router.rules().MatchWork(w).Experiment
	drawn := exp.Pick(cardUUID(81))
	pinned := exp.Settings(exp.Variants[slices.IndexFunc(exp.Variants, func(v rules.Variant) bool { return v.Name == other[drawn.Name] })]).Account

	h.reply(api.HeartbeatReply{BlockedAccounts: []api.BlockedAccount{{Account: pinned}}, WorkRequests: []api.WorkRequest{w}})

	if got := h.router.skips.Pending(); len(got) != 1 || got[0].Account != pinned || h.queueLen() != 0 {
		t.Fatalf("skipped = %+v, want the pinned account %s", got, pinned)
	}
	if len(pins.recorded()) == 0 {
		t.Fatal("the offer read no pin")
	}

	h.reply(api.HeartbeatReply{BlockedAccounts: []api.BlockedAccount{}, WorkRequests: []api.WorkRequest{w}})

	f.mu.Lock()
	accounts := slices.Clone(f.accounts)
	f.mu.Unlock()
	if !slices.Equal(accounts, []string{pinned}) {
		t.Fatalf("claimed accounts = %v, want %s", accounts, pinned)
	}
	if calls := h.worker.recorded(); len(calls) != 1 || calls[0].account != pinned {
		t.Fatalf("runs = %+v, want one on %s", calls, pinned)
	}
}

func TestTheSkippedLogKeepsOneEntryForEachRequestAndDropsWhatWasSent(t *testing.T) {
	var l skipLog
	l.Note("b", "claude")
	l.Note("a", "claude")
	l.Note("a", "claude")
	sent := l.Pending()
	if !slices.Equal(sent, []api.SkippedWork{{WorkRequestID: "a", Account: "claude"}, {WorkRequestID: "b", Account: "claude"}}) {
		t.Fatalf("pending = %+v", sent)
	}
	l.Note("a", "spare")
	l.Drop(sent)
	if got := l.Pending(); !slices.Equal(got, []api.SkippedWork{{WorkRequestID: "a", Account: "spare"}}) {
		t.Fatalf("after drop = %+v", got)
	}
}

func TestAHeartbeatCarriesTheSkippedRequestsUntilTheServerTakesThem(t *testing.T) {
	var l skipLog
	l.Note(workID(1), "claude")
	client := &fakeHeartbeats{errors: []error{errors.New("connection refused")}}
	hh := startHeartbeater(t, client, time.Minute, func(h *heartbeater) { h.skips = &l })
	hh.h.send()
	eventually(t, "the second heartbeat", func() bool { return client.count() >= 2 })
	hh.h.send()
	eventually(t, "a third heartbeat", func() bool { return client.count() >= 3 })

	client.mu.Lock()
	defer client.mu.Unlock()
	carried := 0
	for _, hb := range client.sent {
		if len(hb.Skipped) == 1 && hb.Skipped[0].WorkRequestID == workID(1) {
			carried++
		}
	}
	if carried != 2 {
		t.Fatalf("the skipped request went in %d heartbeats, want the failed one and the next", carried)
	}
	if last := client.sent[len(client.sent)-1]; len(last.Skipped) != 0 {
		t.Fatalf("last heartbeat = %+v", last.Skipped)
	}
}

func TestWorkOutcomeHandsBackARunThatTheLimitStopped(t *testing.T) {
	for name, tc := range map[string]struct {
		res  workerResult
		want string
	}{
		"limited reading, exit 1":    {workerResult{exitCode: 1, limit: limitedReading("week")}, "open"},
		"limited reading, no result": {workerResult{hasResult: false, limit: limitedReading("session")}, "open"},
		"limit message in output":    {workerResult{exitCode: 1, output: "You've hit your session limit · resets 3:45pm"}, "open"},
		"finished with a result":     {workerResult{hasResult: true, status: "finished", limit: limitedReading("week")}, "done"},
		"limit message, good result": {workerResult{hasResult: true, status: "finished", output: "You've hit your session limit · resets 3:45pm"}, "done"},
		"failed, no limit":           {workerResult{exitCode: 1}, "refused"},
		"command":                    {workerResult{command: true, exitCode: 1, limit: limitedReading("week")}, "refused"},
		"timed out":                  {workerResult{timedOut: true, limit: limitedReading("week")}, "refused"},
		"before command":             {workerResult{before: true, exitCode: 1, limit: limitedReading("week")}, "refused"},
	} {
		t.Run(name, func(t *testing.T) {
			if got := workOutcome(tc.res); got.state != tc.want {
				t.Fatalf("state = %q, want %q", got.state, tc.want)
			}
		})
	}
}

func TestLimitedOfPicksTheLimitedWindowThatResetsLast(t *testing.T) {
	week, session := limitedReading("week"), limitedReading("session")
	later := week.ResetsAt.Add(24 * time.Hour)
	week.ResetsAt = &later
	open := limitedReading("other")
	open.Limited = false

	if got := limitedOf([]usage.Reading{*session, *week, *open}); got == nil || got.Window != "week" {
		t.Fatalf("limited = %+v", got)
	}
	if got := limitedOf([]usage.Reading{*open}); got != nil {
		t.Fatalf("limited = %+v", got)
	}
}

func TestWorkOutcomeNamesTheReadingAndThePartwayRun(t *testing.T) {
	got := workOutcome(workerResult{exitCode: 1, limit: limitedReading("week"), assistantTurn: true})
	if got.back == nil || got.back.Cause != api.CauseUsageLimit || got.back.Reading.Window != "week" || !got.back.HasAssistantTurn {
		t.Fatalf("back = %+v", got.back)
	}
	if got.back.Reading.UsedPercent == nil || *got.back.Reading.UsedPercent != 100 || !got.back.Reading.Limited || got.back.Reading.Source != usage.SourceClaudeEvent {
		t.Fatalf("reading = %+v", got.back.Reading)
	}
	if none := workOutcome(workerResult{exitCode: 1, limit: limitedReading("week")}); none.back.HasAssistantTurn {
		t.Fatal("a run with no turn counted as partway")
	}
}

func TestTheLimitMessageGivesAReadingWithNoUse(t *testing.T) {
	got := workOutcome(workerResult{exitCode: 1, output: "You've hit your weekly limit · resets Nov 1, 5pm"})
	if got.back == nil || got.back.Reading.Window != "week" || got.back.Reading.UsedPercent != nil || !got.back.Reading.Limited || got.back.Reading.Source != usage.SourceClaudeMessage {
		t.Fatalf("back = %+v", got.back)
	}
}

// A run that a limit stopped gives its request back, naming the account, the
// reading and the session. Loupe sees no refusal.
func TestALimitedRunHandsItsRequestBack(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	h.worker.result = workerResult{exitCode: 1, limit: limitedReading("session"), assistantTurn: true}

	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " open usage-limit"}) {
		t.Fatalf("results = %v", got)
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	back := f.backs[0]
	calls := h.worker.recorded()
	if back.Account != "claude" || back.SessionID == "" || back.SessionID != calls[0].sessionID || !back.HasAssistantTurn || back.Reading.Window != "session" {
		t.Fatalf("back = %+v, session = %q", back, calls[0].sessionID)
	}
	if got := h.heldClaims(); len(got) != 0 {
		t.Fatalf("claims after the hand-back = %v", got)
	}
}

// The run of a hand-back reads usage-limit, so its record matches the open
// request. A command run, which hands nothing back, keeps the failure.
func TestARunThatHandsItsRequestBackReportsUsageLimit(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	rec := h.states()
	h.worker.result = workerResult{exitCode: 1, limit: limitedReading("session")}

	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunUsageLimit)
	if got := sent[2].report; got.ExitCode == nil || *got.ExitCode != 1 || got.Account != "claude" {
		t.Fatalf("usage-limit = %+v", got)
	}
}

// A run that resumed a session did work earlier, so it counts as partway even
// when it stopped before its first turn.
func TestAResumedRunThatTheLimitStoppedIsPartway(t *testing.T) {
	var p pending
	p.spec.account, p.spec.sessionID, p.spec.resume = "claude", "s1", true
	got := backOf(p, workOutcome(workerResult{exitCode: 1, limit: limitedReading("week")}))
	if got.back == nil || !got.back.HasAssistantTurn || got.back.SessionID != "s1" || got.back.Account != "claude" {
		t.Fatalf("back = %+v", got.back)
	}
}
