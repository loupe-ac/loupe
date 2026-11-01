package cmd

import (
	"errors"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

// The wait after a failed claim starts at claimWaitMin, and doubles up to
// claimWaitMax while the server keeps refusing for good.
const (
	claimWaitMin = time.Minute
	claimWaitMax = 15 * time.Minute
)

// claimBackoff remembers the requests whose claim failed, and until when the
// bridge ignores their offers. The heartbeat offers an open request again at
// each interval, so without it a claim that fails at once repeats at once.
type claimBackoff struct {
	mu sync.Mutex
	m  map[string]claimWait
}

type claimWait struct {
	until time.Time
	// refused counts the lasting refusals in a row.
	refused int
}

// lastingRefusal reports whether the server refused the claim with HTTP 422,
// which does not clear by itself.
func lastingRefusal(err error) bool {
	var refusal *api.WorkRequestRefusal

	return errors.As(err, &refusal) && refusal.Status == 422
}

// waiting reports whether the bridge ignores offers of the request at now.
func (b *claimBackoff) waiting(id string, now time.Time) bool {
	b.mu.Lock()
	defer b.mu.Unlock()
	w, ok := b.m[id]

	return ok && now.Before(w.until)
}

// note records a failed claim and returns the wait it sets. A claim that lost
// a race, or that the bridge never made, sets none and returns zero.
func (b *claimBackoff) note(id string, err error, now time.Time) time.Duration {
	if errors.Is(err, api.ErrWorkRequestAlreadyClaimed) || errors.Is(err, api.ErrWorkRequestNotFound) || errors.Is(err, errClaimHeld) {
		return 0
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	w := b.m[id]
	wait := claimWaitMin
	if lastingRefusal(err) {
		w.refused++
		wait = min(claimWaitMin<<min(w.refused-1, 10), claimWaitMax)
	}
	w.until = now.Add(wait)
	if b.m == nil {
		b.m = map[string]claimWait{}
	}
	b.m[id] = w

	return wait
}

// clear forgets the request.
func (b *claimBackoff) clear(id string) {
	b.mu.Lock()
	defer b.mu.Unlock()
	delete(b.m, id)
}

// reset forgets every request.
func (b *claimBackoff) reset() {
	b.mu.Lock()
	defer b.mu.Unlock()
	clear(b.m)
}
