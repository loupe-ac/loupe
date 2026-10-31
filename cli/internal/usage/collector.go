package usage

import (
	"maps"
	"slices"
	"sync"
	"time"
)

// Collector tails the files of the live runs and keeps, for each account, the
// latest reading of each window that no heartbeat carried yet.
type Collector struct {
	mu      sync.Mutex
	runs    map[string]*liveTail
	pending map[string]map[string]Reading
}

type liveTail struct {
	account string
	tail    *Tail
}

func NewCollector() *Collector {
	return &Collector{runs: map[string]*liveTail{}, pending: map[string]map[string]Reading{}}
}

// Start follows a run of the account. A nil collector does nothing.
func (c *Collector) Start(runID, account string, tail *Tail) {
	if c == nil {
		return
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.runs[runID] = &liveTail{account: account, tail: tail}
}

// Poll reads the new lines of each live run.
func (c *Collector) Poll(now time.Time) {
	if c == nil {
		return
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, run := range c.runs {
		c.keep(run.account, run.tail.Read(now, false))
	}
}

// Finish reads the rest of a run and stops following it.
func (c *Collector) Finish(runID string, now time.Time) {
	if c == nil {
		return
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	run, ok := c.runs[runID]
	if !ok {
		return
	}
	delete(c.runs, runID)
	c.keep(run.account, run.tail.Read(now, true))
}

func (c *Collector) keep(account string, readings []Reading) {
	for _, r := range readings {
		if c.pending[account] == nil {
			c.pending[account] = map[string]Reading{}
		}
		if old, ok := c.pending[account][r.Window]; !ok || !r.ReadAt.Before(old.ReadAt) {
			c.pending[account][r.Window] = r
		}
	}
}

// Pending gives the readings that no heartbeat carried, by account, in window
// name order.
func (c *Collector) Pending() map[string][]Reading {
	if c == nil {
		return nil
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	out := map[string][]Reading{}
	for account, windows := range c.pending {
		for _, w := range slices.Sorted(maps.Keys(windows)) {
			out[account] = append(out[account], windows[w])
		}
	}

	return out
}

// Drop forgets the readings that an accepted heartbeat carried. A window that
// read newer since stays.
func (c *Collector) Drop(sent map[string][]Reading) {
	if c == nil {
		return
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	for account, readings := range sent {
		for _, r := range readings {
			if cur, ok := c.pending[account][r.Window]; ok && !cur.ReadAt.After(r.ReadAt) {
				delete(c.pending[account], r.Window)
			}
		}
		if len(c.pending[account]) == 0 {
			delete(c.pending, account)
		}
	}
}
