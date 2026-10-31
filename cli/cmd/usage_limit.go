package cmd

import (
	"bytes"
	"cmp"
	"context"
	"errors"
	"net/http"
	"regexp"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/usage"
)

// blockedSet is the accounts Loupe says take no new work. The latest reply
// that holds a list replaces it.
type blockedSet struct {
	mu sync.Mutex
	m  map[string]time.Time
}

func (b *blockedSet) set(list []api.BlockedAccount) {
	m := make(map[string]time.Time, len(list))
	for _, a := range list {
		m[a.Account] = a.Until
	}
	b.mu.Lock()
	b.m = m
	b.mu.Unlock()
}

func (b *blockedSet) remove(account string) {
	b.mu.Lock()
	delete(b.m, account)
	b.mu.Unlock()
}

func (b *blockedSet) has(account string) bool {
	b.mu.Lock()
	defer b.mu.Unlock()
	_, ok := b.m[account]

	return ok
}

// skipLog is the offered requests the bridge skipped because their account is
// blocked, and that no accepted heartbeat has carried yet. A nil log holds
// nothing.
type skipLog struct {
	mu sync.Mutex
	m  map[string]string
}

// Note records that the bridge skipped the request with the account it matched.
func (l *skipLog) Note(id, account string) {
	if l == nil {
		return
	}
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.m == nil {
		l.m = map[string]string{}
	}
	l.m[id] = account
}

// Pending gives the skipped requests in id order.
func (l *skipLog) Pending() []api.SkippedWork {
	if l == nil {
		return nil
	}
	l.mu.Lock()
	defer l.mu.Unlock()
	var out []api.SkippedWork
	for id, account := range l.m {
		out = append(out, api.SkippedWork{WorkRequestID: id, Account: account})
	}
	slices.SortFunc(out, func(a, b api.SkippedWork) int { return cmp.Compare(a.WorkRequestID, b.WorkRequestID) })

	return out
}

// Drop forgets the entries an accepted heartbeat carried. An entry that
// changed its account since then stays.
func (l *skipLog) Drop(sent []api.SkippedWork) {
	if l == nil {
		return
	}
	l.mu.Lock()
	defer l.mu.Unlock()
	for _, s := range sent {
		if l.m[s.WorkRequestID] == s.Account {
			delete(l.m, s.WorkRequestID)
		}
	}
}

// skipBlocked drops the queued copy of a request whose account is blocked, and
// reports the skip with the next heartbeat. The request stays open in Loupe.
func (r *router) skipBlocked(w api.WorkRequest, account, source string) {
	r.mu.Lock()
	r.dropQueuedWorkLocked(w.WorkRequestID)
	r.mu.Unlock()
	r.skips.Note(w.WorkRequestID, account)
	r.log.Debug("work_request_skipped", append(workAttrs(w, source), "reason", "account_blocked", "account", account)...)
}

// settlement is how a work request ends. A hand-back opens the request again
// in place of a result.
type settlement struct {
	state, reason string
	back          *api.HandBack
}

// limitedOf is the limited reading that resets last, and nil when none is
// limited. A reading with no reset time comes first.
func limitedOf(readings []usage.Reading) *usage.Reading {
	var out *usage.Reading
	for i := range readings {
		r := &readings[i]
		if !r.Limited {
			continue
		}
		if out == nil || (r.ResetsAt != nil && (out.ResetsAt == nil || r.ResetsAt.After(*out.ResetsAt))) {
			out = r
		}
	}

	return out
}

// limitHandBack is the hand-back of a run that a usage limit stopped, or nil.
// The last reading of the run says it, or the output of the run holds the
// limit message. A run that left a good result is done, whatever it read.
func limitHandBack(res workerResult) *api.HandBack {
	if !endedShort(res) {
		return nil
	}
	reading := res.limit
	if reading == nil {
		reading = limitInOutput(res.output)
	}
	if reading == nil {
		return nil
	}

	return &api.HandBack{
		Cause:            api.CauseUsageLimit,
		HasAssistantTurn: res.assistantTurn,
		Reading: &api.HandBackReading{
			Window: reading.Window, UsedPercent: reading.UsedPercent, ResetsAt: reading.ResetsAt,
			Limited: true, Source: reading.Source,
		},
	}
}

// endedShort reports whether a run ended with no good result and no cause of
// its own, so a hand-back can name one. A timeout, a command, a before command
// and a gone resume folder fail for reasons that a retry does not mend.
func endedShort(res workerResult) bool {
	switch {
	case res.timedOut, res.command, res.before, res.resumeGone, res.err != nil:
		return false
	case res.exitCode == 0 && res.hasResult:
		return false
	}

	return true
}

// limitInOutput reads a usage limit message from a line of the output.
func limitInOutput(output string) *usage.Reading {
	now := time.Now()
	for line := range bytes.SplitSeq([]byte(output), []byte{'\n'}) {
		if got := usage.ParseLimitMessage(string(line), now); len(got) > 0 {
			return &got[0]
		}
	}

	return nil
}

// backOf adds the account and the session of the run to a hand-back. A run
// that resumed a session did work before, so it counts as partway.
func backOf(p pending, s settlement) settlement {
	if s.back == nil {
		return s
	}
	s.back.Account, s.back.SessionID = p.spec.account, p.spec.sessionID
	s.back.HasAssistantTurn = s.back.HasAssistantTurn || p.spec.resume

	return s
}

// tryNow frees an account for new work. The next reply replaces the list, and
// Loupe leaves the account out of it while the override holds. The heartbeat
// goes out at once, so Loupe offers the waiting work now.
func (r *router) tryNow(c api.Command) (state, reason string) {
	r.blocked.remove(c.Account)
	r.log.Info("account_try_now", "command", c.CommandID, "account", c.Account)
	if r.heartbeat != nil {
		r.heartbeat.send()
	}

	return api.CommandDone, ""
}

// networkCode matches the error codes of a failed connection. The match is
// case sensitive, because the codes are upper case words.
var networkCode = regexp.MustCompile(`\b(ENOTFOUND|ECONNREFUSED|ETIMEDOUT)\b`)

// networkInOutput reports whether the output names a failed connection.
func networkInOutput(output string) bool {
	return networkCode.MatchString(output) || strings.Contains(strings.ToLower(output), "connection error")
}

// networkHandBack is the hand-back of a run that a network outage stopped, or
// nil. The call is a guess, so it needs a run with no good result and one
// sign: a retry with no HTTP status, an error text that names a failed
// connection, or a Loupe that the bridge could not reach in the last minute.
// An output that holds a structured result is the agent's own words, so it
// proves nothing. A usage limit is checked first and wins.
func networkHandBack(res workerResult) *api.HandBack {
	if !endedShort(res) {
		return nil
	}
	if !res.retryNoStatus && !res.loupeDown && (res.hasResult || !networkInOutput(res.output)) {
		return nil
	}

	return &api.HandBack{Cause: api.CauseNetwork, HasAssistantTurn: res.assistantTurn}
}

// handBackState is the run state of a run that hands its request back.
func handBackState(back *api.HandBack) string {
	if back.Cause == api.CauseNetwork {
		return api.RunNetwork
	}

	return api.RunUsageLimit
}

// reachTracker records when a request to Loupe last failed to arrive. The
// zero value is ready, and a nil one reports no failure.
type reachTracker struct {
	mu   sync.Mutex
	last time.Time
}

func (t *reachTracker) note(at time.Time) {
	t.mu.Lock()
	defer t.mu.Unlock()
	if at.After(t.last) {
		t.last = at
	}
}

// observe notes a transport failure. A request that the bridge cancelled
// says nothing about the network.
func (t *reachTracker) observe(err error, at time.Time) {
	if err != nil && !errors.Is(err, context.Canceled) {
		t.note(at)
	}
}

// failedSince reports whether a request failed at or after since.
func (t *reachTracker) failedSince(since time.Time) bool {
	if t == nil {
		return false
	}
	t.mu.Lock()
	defer t.mu.Unlock()

	return !t.last.IsZero() && !t.last.Before(since)
}

// reachTransport feeds a tracker from the round trips of an HTTP client.
type reachTransport struct {
	base    http.RoundTripper
	tracker *reachTracker
}

func (t reachTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	resp, err := t.base.RoundTrip(req)
	t.tracker.observe(err, time.Now())

	return resp, err
}
