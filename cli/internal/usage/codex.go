package usage

import (
	"bytes"
	"encoding/json"
	"strings"
	"time"
)

// codexLimitID is the limit of the account itself. Codex logs the limits of
// a single model under their own ids.
const codexLimitID = "codex"

type codexWindow struct {
	UsedPercent   float64 `json:"used_percent"`
	WindowMinutes int     `json:"window_minutes"`
	ResetsAt      int64   `json:"resets_at"`
}

// ParseCodex reads one line of a Codex session file. The rate_limits of a
// token count give the primary and the secondary window. A line without them
// gives no reading.
func ParseCodex(line []byte, now time.Time) []Reading {
	line = bytes.TrimSpace(line)
	if len(line) == 0 || line[0] != '{' {
		return nil
	}
	var e struct {
		Timestamp time.Time `json:"timestamp"`
		Payload   struct {
			RateLimits *struct {
				LimitID   string       `json:"limit_id"`
				Primary   *codexWindow `json:"primary"`
				Secondary *codexWindow `json:"secondary"`
				Reached   *string      `json:"rate_limit_reached_type"`
			} `json:"rate_limits"`
		} `json:"payload"`
	}
	if json.Unmarshal(line, &e) != nil || e.Payload.RateLimits == nil {
		return nil
	}
	rl := e.Payload.RateLimits
	if rl.LimitID != "" && rl.LimitID != codexLimitID {
		return nil
	}
	readAt := now
	if !e.Timestamp.IsZero() {
		readAt = e.Timestamp.UTC()
	}

	windows := map[string]*codexWindow{"primary": rl.Primary, "secondary": rl.Secondary}
	limited := ""
	if rl.Reached != nil {
		limited = limitedWindow(*rl.Reached, windows)
	}
	var out []Reading
	for _, key := range []string{"primary", "secondary"} {
		w := windows[key]
		if w == nil {
			continue
		}
		out = append(out, Reading{
			Window:      windowName(w.WindowMinutes),
			UsedPercent: percent(w.UsedPercent),
			ResetsAt:    unixTime(w.ResetsAt),
			Limited:     key == limited,
			Source:      SourceCodex,
			ReadAt:      readAt,
		})
	}

	return out
}

// limitedWindow picks the window that a reached limit names. A name that holds
// neither primary nor secondary picks the window with the most use.
func limitedWindow(reached string, windows map[string]*codexWindow) string {
	for _, key := range []string{"primary", "secondary"} {
		if strings.Contains(strings.ToLower(reached), key) && windows[key] != nil {
			return key
		}
	}
	best := ""
	for _, key := range []string{"primary", "secondary"} {
		if windows[key] != nil && (best == "" || windows[key].UsedPercent > windows[best].UsedPercent) {
			best = key
		}
	}

	return best
}
