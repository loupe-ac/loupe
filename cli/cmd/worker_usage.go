package cmd

import (
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	harn "github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/stream"
	"github.com/loupe-ac/loupe/cli/internal/transcript"
)

// sessionBaseline is what the whole session spent before a resume, and nil
// when the harness cannot read it.
func sessionBaseline(h harn.Harness, sessionID string) *transcript.Usage {
	usage, err := h.SessionTotal(sessionID)
	if err != nil {
		return nil
	}

	return &usage
}

// sessionLastTurn is when the session last answered before a resume, and nil
// when the harness cannot say.
func sessionLastTurn(h harn.Harness, sessionID string) *time.Time {
	at, err := h.SessionLastTurn(sessionID)
	if err != nil || at.IsZero() {
		return nil
	}

	return &at
}

// wakeOf is what woke the resume of rec, and nil for a run that is no resume
// or a record with no cause. It holds a count and a time, never text.
func wakeOf(rec runRecord, first *stream.TurnUsage) *api.Wake {
	if !rec.Resume || rec.Cause == "" {
		return nil
	}
	wake := &api.Wake{Trigger: rec.Cause, LastTurnAt: rec.LastTurnAt}
	if first != nil {
		wake.FirstTurn = &api.TurnUsage{InputTokens: first.InputTokens, CacheReadTokens: first.CacheReadTokens, CacheWriteTokens: first.CacheWriteTokens}
	}

	return wake
}

// workerUsage is what the process of rec spent. claude's own counts cover the
// whole session, so a resume subtracts the baseline it read before it started,
// and one with no baseline sends the session as an estimate. A process that
// printed no counts is estimated from its transcript messages.
func workerUsage(rec runRecord, reported transcript.Usage) *api.Usage {
	switch {
	case reported != nil && !rec.Resume:
		return apiUsage(api.UsageReported, reported)
	case reported != nil && rec.Baseline != nil:
		return apiUsage(api.UsageReported, reported.Minus(*rec.Baseline))
	case reported != nil:
		return apiUsage(api.UsageEstimated, reported)
	}
	// A record from an older image has no launch time.
	since := rec.LaunchedAt
	if since.IsZero() {
		since = rec.StartedAt
	}
	if since.IsZero() {
		return nil
	}

	usage, err := recordHarness(rec).SessionUsage(rec.SessionID, since, time.Time{})
	if err != nil {
		return nil
	}

	return apiUsage(api.UsageEstimated, usage)
}

func apiUsage(source string, usage transcript.Usage) *api.Usage {
	models := make(map[string]api.ModelUsage, len(usage))
	for name, m := range usage {
		models[name] = api.ModelUsage(m)
	}

	return &api.Usage{Source: source, Models: models}
}

// unpriced is the usage with no cost on any model. Loupe takes the cost of a
// run on OpenRouter from the bill, and a list price is not the billed price.
func unpriced(usage *api.Usage) *api.Usage {
	if usage == nil {
		return nil
	}
	models := make(map[string]api.ModelUsage, len(usage.Models))
	for name, m := range usage.Models {
		m.CostUSD = nil
		models[name] = m
	}

	return &api.Usage{Source: usage.Source, Models: models}
}
