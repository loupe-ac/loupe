// Package usage reads how much of its limits an agent account has used. A
// reader turns one line of the output of Claude Code or of a Codex session file
// into readings, and a collector keeps the latest reading of each window.
package usage

import (
	"strconv"
	"time"
)

// The sources of a reading.
const (
	SourceClaudeEvent   = "claude-event"
	SourceClaudeStatus  = "claude-status"
	SourceClaudeMessage = "claude-message"
	SourceCodex         = "codex"
)

// The most a reading can say a window used, in percent.
const maxPercent = 200

// Reading is the use of one window of an account. UsedPercent is nil when the
// source gives no use. ResetsAt is nil when the source gives no reset time.
type Reading struct {
	Window      string     `json:"window"`
	UsedPercent *float64   `json:"usedPercent"`
	ResetsAt    *time.Time `json:"resetsAt"`
	Limited     bool       `json:"limited"`
	Source      string     `json:"source"`
	ReadAt      time.Time  `json:"readAt"`
}

// Parser turns one line into readings, read at now.
type Parser func(line []byte, now time.Time) []Reading

// windowName names a window by its length: session for 5 hours, week for 7
// days, then hours, days, and minutes for a length that is neither.
func windowName(minutes int) string {
	switch {
	case minutes == 300:
		return "session"
	case minutes == 7*24*60:
		return "week"
	case minutes > 0 && minutes%(24*60) == 0:
		return strconv.Itoa(minutes/(24*60)) + "d"
	case minutes > 0 && minutes%60 == 0:
		return strconv.Itoa(minutes/60) + "h"
	}

	return strconv.Itoa(minutes) + "m"
}

func percent(v float64) *float64 {
	v = min(max(v, 0), maxPercent)

	return &v
}

// unixTime reads a time that a source gives in seconds, or in milliseconds.
func unixTime(n int64) *time.Time {
	if n <= 0 {
		return nil
	}
	var t time.Time
	if n > 1e11 {
		t = time.UnixMilli(n).UTC()
	} else {
		t = time.Unix(n, 0).UTC()
	}

	return &t
}
