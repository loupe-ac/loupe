package usage

import (
	"maps"
	"regexp"
	"slices"
	"sync"
	"time"
)

// maxResetLead is the furthest ahead of now that Loupe accepts a reset time.
const maxResetLead = 31 * 24 * time.Hour

// windowPattern is the window name that Loupe accepts.
var windowPattern = regexp.MustCompile(`^[a-z0-9]{1,16}$`)

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
		c.keep(run.account, run.tail.Read(now, false), now)
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
	c.keep(run.account, run.tail.Read(now, true), now)
}

// keep drops a reading that Loupe would refuse, because Loupe then refuses the
// whole heartbeat that carries it: a reading with no reset time, a reset time
// too far ahead, or a window name out of its pattern.
func (c *Collector) keep(account string, readings []Reading, now time.Time) {
	for _, r := range readings {
		if r.ResetsAt == nil || r.ResetsAt.After(now.Add(maxResetLead)) || !windowPattern.MatchString(r.Window) {
			continue
		}
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
