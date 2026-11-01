package cmd

import (
	"errors"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

func TestAClaimWaitDoublesOnALastingRefusalUpToTheCap(t *testing.T) {
	var b claimBackoff
	now := time.Date(2026, 11, 1, 12, 0, 0, 0, time.UTC)
	refusal := &api.WorkRequestRefusal{Status: 422, Code: "account_unknown"}

	for i, want := range []time.Duration{1, 2, 4, 8, 15, 15, 15} {
		if got := b.note("a", refusal, now); got != want*time.Minute {
			t.Fatalf("refusal %d waits %s, want %s", i+1, got, want*time.Minute)
		}
		if !b.waiting("a", now.Add(want*time.Minute-time.Second)) || b.waiting("a", now.Add(want*time.Minute)) {
			t.Fatalf("refusal %d: the wait does not end at %s", i+1, want*time.Minute)
		}
		now = now.Add(want * time.Minute)
	}
}

func TestAClaimWaitStaysAtOneMinuteForAFailureThatClearsByItself(t *testing.T) {
	var b claimBackoff
	now := time.Now()

	for name, err := range map[string]error{
		"rate limited": &api.WorkRequestRefusal{Status: 429},
		"network":      errors.New("dial tcp: connection refused"),
		"unsupported":  api.ErrWorkRequestsUnsupported,
	} {
		for range 3 {
			if got := b.note("a", err, now); got != time.Minute {
				t.Fatalf("%s waits %s, want 1m", name, got)
			}
		}
	}
}

func TestAnAnswerOtherThanALastingRefusalEndsTheRunOfRefusals(t *testing.T) {
	refusal := &api.WorkRequestRefusal{Status: 422, Code: "account_unknown"}

	for name, err := range map[string]error{
		"rate limited":    &api.WorkRequestRefusal{Status: 429},
		"network":         errors.New("dial tcp: connection refused"),
		"already claimed": api.ErrWorkRequestAlreadyClaimed,
		"not found":       api.ErrWorkRequestNotFound,
	} {
		var b claimBackoff
		now := time.Now()
		b.note("a", refusal, now)
		b.note("a", refusal, now)
		b.note("a", err, now)
		if got := b.note("a", refusal, now); got != time.Minute {
			t.Fatalf("a refusal after %s waits %s, want 1m", name, got)
		}
	}
}

func TestAClaimTheBridgeNeverMadeKeepsTheRunOfRefusalsAndTheWait(t *testing.T) {
	var b claimBackoff
	now := time.Now()
	refusal := &api.WorkRequestRefusal{Status: 422, Code: "account_unknown"}
	b.note("a", refusal, now)

	if got := b.note("a", errClaimHeld, now); got != 0 {
		t.Fatalf("a held claim sets a wait of %s", got)
	}
	if !b.waiting("a", now) {
		t.Fatal("a held claim ends the wait")
	}
	if got := b.note("a", refusal, now); got != 2*time.Minute {
		t.Fatalf("the next refusal waits %s, want 2m", got)
	}
}

func TestALostRaceEndsTheWait(t *testing.T) {
	var b claimBackoff
	now := time.Now()
	b.note("a", errors.New("down"), now)
	b.note("a", api.ErrWorkRequestAlreadyClaimed, now)
	if b.waiting("a", now) {
		t.Fatal("a lost race leaves a wait")
	}
}

func TestAClaimWaitIsPerRequestAndNeedsNoWaitForALostRace(t *testing.T) {
	var b claimBackoff
	now := time.Now()

	for _, err := range []error{api.ErrWorkRequestAlreadyClaimed, api.ErrWorkRequestNotFound, errClaimHeld} {
		if got := b.note("a", err, now); got != 0 || b.waiting("a", now) {
			t.Fatalf("%v sets a wait of %s", err, got)
		}
	}
	b.note("a", errors.New("down"), now)
	if b.waiting("b", now) {
		t.Fatal("another request waits")
	}
	b.clear("a")
	if b.waiting("a", now) {
		t.Fatal("a cleared request waits")
	}
	b.note("a", errors.New("down"), now)
	b.note("b", errors.New("down"), now)
	b.reset()
	if b.waiting("a", now) || b.waiting("b", now) {
		t.Fatal("a reset leaves a wait")
	}
}
