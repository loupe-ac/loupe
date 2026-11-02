package cmd

import (
	"errors"
	"slices"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// backoffHarness is a harness whose claim waits count on a clock the test moves.
func backoffHarness(t *testing.T) (*harness, *fakeWork, *fakeClock) {
	t.Helper()
	h := newHarnessWith(t, workRules, rules.Defaults{})
	clock := &fakeClock{t: time.Date(2026, 11, 1, 12, 0, 0, 0, time.UTC)}
	h.router.claimClock = clock.now

	return h, h.withWork(), clock
}

// offerOnce answers one heartbeat that offers the request.
func (h *harness) offerOnce(f *fakeWork, w api.WorkRequest) {
	f.mu.Lock()
	f.requests[w.WorkRequestID] = w
	f.mu.Unlock()
	h.reply(api.HeartbeatReply{WorkRequests: []api.WorkRequest{w}})
}

func TestAFailedClaimMakesNoSecondClaimBeforeTheWaitEnds(t *testing.T) {
	h, f, clock := backoffHarness(t)
	f.errs[workID(1)] = errors.New("dial tcp: connection refused")
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)

	h.offerOnce(f, w)
	h.offerOnce(f, w)
	h.send(workPayload(w))
	clock.advance(59 * time.Second)
	h.offerOnce(f, w)
	if got := f.claimed(); len(got) != 1 {
		t.Fatalf("claims = %v, want one inside the wait", got)
	}
	if h.queueLen() != 0 || h.runs() != 0 {
		t.Fatalf("queue = %d, runs = %d", h.queueLen(), h.runs())
	}
	if line := h.only(t, "work_request_not_claimed"); num(t, line, "retry_in_seconds") != 60 {
		t.Fatalf("log = %v", line)
	}

	clock.advance(time.Second)
	h.offerOnce(f, w)
	if got := f.claimed(); len(got) != 2 {
		t.Fatalf("claims = %v, want a second one after the wait", got)
	}
}

func TestALastingRefusalDoublesTheWaitToTheCapAndAsksForAnUpdateCheck(t *testing.T) {
	h, f, clock := backoffHarness(t)
	checks := 0
	h.router.updateCheck = func() { checks++ }
	f.errs[workID(1)] = &api.WorkRequestRefusal{Status: 422, Code: "account_unknown"}
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)

	for i, minutes := range []int{1, 2, 4, 8, 15, 15} {
		h.offerOnce(f, w)
		if len(f.claimed()) != i+1 || checks != i+1 {
			t.Fatalf("round %d: %d claims, %d update checks", i+1, len(f.claimed()), checks)
		}
		wait := time.Duration(minutes) * time.Minute
		clock.advance(wait - time.Second)
		h.offerOnce(f, w)
		if len(f.claimed()) != i+1 {
			t.Fatalf("round %d: a claim inside the wait of %s", i+1, wait)
		}
		clock.advance(time.Second)
	}
	lines := h.events(t, "work_request_not_claimed")
	if got := num(t, lines[len(lines)-1], "retry_in_seconds"); got != 900 {
		t.Fatalf("last wait = %d s, want 900", got)
	}
}

func TestAFailureThatClearsByItselfAsksForNoUpdateCheck(t *testing.T) {
	h, f, _ := backoffHarness(t)
	checks := 0
	h.router.updateCheck = func() { checks++ }
	f.errs[workID(1)] = &api.WorkRequestRefusal{Status: 429}

	h.offerOnce(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	if checks != 0 {
		t.Fatalf("%d update checks", checks)
	}
}

func TestALostRaceSetsNoWait(t *testing.T) {
	h, f, _ := backoffHarness(t)
	f.errs[workID(1)] = api.ErrWorkRequestAlreadyClaimed
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)

	h.offerOnce(f, w)
	h.offerOnce(f, w)

	if got := f.claimed(); len(got) != 2 {
		t.Fatalf("claims = %v, want a claim for each offer", got)
	}
}

func TestAClaimThatLandsClearsTheWait(t *testing.T) {
	h, f, clock := backoffHarness(t)
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)
	f.errs[workID(1)] = errors.New("down")
	h.offerOnce(f, w)
	f.mu.Lock()
	delete(f.errs, workID(1))
	f.mu.Unlock()
	clock.advance(time.Minute)

	h.offerOnce(f, w)

	if h.router.claimWaits.waiting(workID(1), clock.now()) {
		t.Fatal("a claim that landed leaves a wait")
	}
}

func TestARequestInAnotherStateAndAReloadClearTheWait(t *testing.T) {
	h, f, clock := backoffHarness(t)
	f.errs[workID(1)] = errors.New("down")
	f.errs[workID(2)] = errors.New("down")
	h.offerOnce(f, workRequest(1, 87, "implement", api.WorkRequestOpen))
	h.offerOnce(f, workRequest(2, 88, "implement", api.WorkRequestOpen))

	h.send(workPayload(workRequest(1, 87, "implement", api.WorkRequestCancelled)))
	if h.router.claimWaits.waiting(workID(1), clock.now()) || !h.router.claimWaits.waiting(workID(2), clock.now()) {
		t.Fatal("a cancelled request keeps its wait, or another request lost its own")
	}

	if res := h.reload(t, workRules); !res.OK {
		t.Fatalf("reload = %+v", res)
	}
	if h.router.claimWaits.waiting(workID(2), clock.now()) {
		t.Fatal("a reload keeps the wait")
	}
	h.offerOnce(f, workRequest(2, 88, "implement", api.WorkRequestOpen))
	if got := f.claimed(); !slices.Equal(got, []string{workID(1), workID(2), workID(2)}) {
		t.Fatalf("claims = %v", got)
	}
}
