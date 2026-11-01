package cmd

import (
	"context"
	"errors"
	"slices"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

func TestWorkOutcomeHandsBackARunThatTheNetworkStopped(t *testing.T) {
	for name, tc := range map[string]struct {
		res  workerResult
		want string
	}{
		"retry line with no status":  {workerResult{exitCode: 1, retryNoStatus: true}, "open"},
		"ENOTFOUND":                  {workerResult{exitCode: 1, output: "API Error: Unable to connect to API (ENOTFOUND)"}, "open"},
		"ECONNREFUSED":               {workerResult{exitCode: 1, output: "connect ECONNREFUSED 127.0.0.1:443"}, "open"},
		"ETIMEDOUT":                  {workerResult{exitCode: 1, output: "read ETIMEDOUT"}, "open"},
		"connection error":           {workerResult{exitCode: 1, output: "Connection error."}, "open"},
		"lower case connection":      {workerResult{exitCode: 1, output: "an unexpected connection error"}, "open"},
		"loupe unreachable":          {workerResult{exitCode: 1, loupeDown: true}, "open"},
		"no result, loupe down":      {workerResult{loupeDown: true}, "open"},
		"lower case code is no code": {workerResult{exitCode: 1, output: "enotfound"}, "refused"},
		"plain failure":              {workerResult{exitCode: 1, output: "tests failed"}, "refused"},
		"good result":                {workerResult{hasResult: true, status: "finished", retryNoStatus: true, loupeDown: true, output: "ECONNREFUSED"}, "done"},
		"error text, agent result":   {workerResult{exitCode: 1, hasResult: true, status: "blocked", output: "the API gave ECONNREFUSED in my test"}, "refused"},
		"timed out":                  {workerResult{timedOut: true, retryNoStatus: true}, "refused"},
		"command":                    {workerResult{command: true, exitCode: 1, loupeDown: true}, "refused"},
		"before command":             {workerResult{before: true, exitCode: 1, retryNoStatus: true}, "refused"},
		"resume gone":                {workerResult{resumeGone: true, retryNoStatus: true}, "refused"},
	} {
		t.Run(name, func(t *testing.T) {
			got := workOutcome(tc.res)
			if got.state != tc.want {
				t.Fatalf("state = %q, want %q", got.state, tc.want)
			}
			if tc.want == "open" && (got.back == nil || got.back.Cause != api.CauseNetwork || got.back.Reading != nil) {
				t.Fatalf("back = %+v", got.back)
			}
		})
	}
}

func TestAUsageLimitBeatsANetworkError(t *testing.T) {
	got := workOutcome(workerResult{exitCode: 1, limit: limitedReading("week"), retryNoStatus: true, loupeDown: true})
	if got.back == nil || got.back.Cause != api.CauseUsageLimit || got.back.Reading == nil {
		t.Fatalf("back = %+v", got.back)
	}
}

func TestANetworkHandBackIsPartwayAfterATurn(t *testing.T) {
	if got := workOutcome(workerResult{exitCode: 1, retryNoStatus: true, assistantTurn: true}); !got.back.HasAssistantTurn {
		t.Fatal("a run with a turn did not count as partway")
	}
	if got := workOutcome(workerResult{exitCode: 1, retryNoStatus: true}); got.back.HasAssistantTurn {
		t.Fatal("a run with no turn counted as partway")
	}
	var p pending
	p.spec.account, p.spec.sessionID, p.spec.resume = "claude", "s1", true
	got := backOf(p, workOutcome(workerResult{exitCode: 1, retryNoStatus: true}))
	if !got.back.HasAssistantTurn || got.back.SessionID != "s1" || got.back.Account != "claude" {
		t.Fatalf("back = %+v", got.back)
	}
}

func TestTheReachTrackerAnswersForTheLastMinute(t *testing.T) {
	end := time.Date(2026, 11, 1, 12, 0, 0, 0, time.UTC)
	var tr reachTracker
	if tr.failedSince(end.Add(-time.Minute)) {
		t.Fatal("an empty tracker reported a failure")
	}
	tr.note(end.Add(-2 * time.Minute))
	if tr.failedSince(end.Add(-time.Minute)) {
		t.Fatal("a failure two minutes old counted")
	}
	tr.note(end.Add(-30 * time.Second))
	if !tr.failedSince(end.Add(-time.Minute)) {
		t.Fatal("a failure 30 seconds old did not count")
	}
	tr.note(end.Add(-3 * time.Minute))
	if !tr.failedSince(end.Add(-time.Minute)) {
		t.Fatal("an older failure hid the newer one")
	}
	var none *reachTracker
	if none.failedSince(end) {
		t.Fatal("a nil tracker reported a failure")
	}
}

func TestTheReachTrackerSeesATransportFailureAlone(t *testing.T) {
	var tr reachTracker
	now := time.Now()
	tr.observe(nil, now)
	tr.observe(context.Canceled, now)
	if tr.failedSince(now.Add(-time.Second)) {
		t.Fatal("a success or a cancel counted")
	}
	tr.observe(errors.New("dial tcp: lookup app.loupe.ac: no such host"), now)
	if !tr.failedSince(now.Add(-time.Second)) {
		t.Fatal("a transport failure did not count")
	}
}

func TestALoupeOutageInTheLastMinuteHandsTheRequestBack(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	h.router.reach = &reachTracker{}
	h.router.reach.note(time.Now())
	h.worker.result = workerResult{exitCode: 1, assistantTurn: true}

	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " open network"}) {
		t.Fatalf("results = %v", got)
	}
}

func TestALoupeOutageBeforeTheLastMinuteHandsNothingBack(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	h.router.reach = &reachTracker{}
	h.router.reach.note(time.Now().Add(-5 * time.Minute))
	h.worker.result = workerResult{exitCode: 1}

	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	if got := f.settled(); len(got) != 1 {
		t.Fatalf("results = %v", got)
	}
	if f.backCount() != 0 {
		t.Fatal("a run handed back for an old outage")
	}
}

func TestARunThatHandsItsRequestBackForTheNetworkReportsNetwork(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	rec := h.states()
	h.worker.result = workerResult{exitCode: 1, retryNoStatus: true}

	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunNetwork)
	if got := sent[2].report; got.ExitCode == nil || *got.ExitCode != 1 {
		t.Fatalf("network = %+v", got)
	}
}

func (f *fakeWork) backCount() int {
	f.mu.Lock()
	defer f.mu.Unlock()

	return len(f.backs)
}

// A heartbeat that names a claim lost stops its live worker. The reopened
// request starts only after that worker has exited, so two runs never share
// one session.
func TestALostClaimStopsItsWorkerBeforeTheRequestStartsAgain(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	s := h.stopper()
	s.gone = true
	block := h.blocked()
	s.on = func(sig stopSignal) {
		if sig == stopInt {
			close(block)
		}
	}
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)
	f.requests[w.WorkRequestID] = w
	server := &replying{reply: api.HeartbeatReply{LostClaims: []string{workID(1)}, WorkRequests: []api.WorkRequest{w}}}
	hb := newHeartbeater(context.Background(), latestNow{}, server, testBridgeID, heartbeatBody(h.router.rules()), time.Minute, h.router.log)
	hb.onReply, hb.onLost = h.router.onHeartbeatReply, h.router.loseClaims
	h.router.heartbeat = hb

	h.router.onData([]byte(workPayload(w)))
	<-h.worker.started
	hb.send()
	h.router.wg.Wait()

	s.mu.Lock()
	sent := slices.Clone(s.sent)
	s.mu.Unlock()
	if !slices.Contains(sent, stopInt) {
		t.Fatalf("signals = %v, want the first worker stopped", sent)
	}
	if got := h.worker.peak(); got != 1 {
		t.Fatalf("workers at once = %d, want 1", got)
	}
	if got := h.runs(); got != 2 {
		t.Fatalf("runs = %d, want the stopped run and the new one", got)
	}
}

// The server answers a late result of an old claim with claim_lost. A
// hand-back is no exception. The bridge drops it with no retry, and the claim
// ends.
func TestALateHandBackOfALostClaimIsDroppedWithoutARetry(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	h.router.reports = syncQueue{}
	h.router.runs = newRunReports(&stateRecorder{}, h.router.log)
	f := h.withWork()
	f.settleErr = api.ErrClaimLost
	h.worker.result = workerResult{exitCode: 1, retryNoStatus: true}

	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	if got := f.settled(); len(got) != 1 || f.backCount() != 1 {
		t.Fatalf("results = %v, hand-backs = %d, want one try", got, f.backCount())
	}
	if got := h.heldClaims(); len(got) != 0 {
		t.Fatalf("claims = %v", got)
	}
	h.only(t, "work_request_result_lost")
}
