package usage

import (
	"bytes"
	"encoding/json"
	"math"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// sessionWindow is the longest reset time, from now, that a window with no
// name can have and still read as a session.
const sessionWindow = 5 * time.Hour

var claudeWindows = map[string]string{"five_hour": "session", "seven_day": "week"}

type claudeWindow struct {
	Utilization *float64 `json:"utilization"`
	ResetsAt    int64    `json:"resetsAt"`
	Status      string   `json:"status"`
}

// ParseClaude reads one line of the stream-json output of Claude Code. It
// reads a rate_limit_event line, and the message of a usage limit. A line that
// holds neither gives no reading. now also gives the time zone of a reset time
// that the message writes as a clock time.
func ParseClaude(line []byte, now time.Time) []Reading {
	line = bytes.TrimSpace(line)
	if len(line) == 0 || line[0] != '{' {
		return nil
	}
	var e struct {
		Type    string `json:"type"`
		IsError bool   `json:"is_error"`
		Result  string `json:"result"`
		Message struct {
			Content []struct {
				Type string `json:"type"`
				Text string `json:"text"`
			} `json:"content"`
		} `json:"message"`
		Info json.RawMessage `json:"rate_limit_info"`
	}
	if json.Unmarshal(line, &e) != nil {
		return nil
	}
	switch e.Type {
	case "rate_limit_event":
		return claudeEvent(e.Info, now)
	case "result":
		if e.IsError {
			return claudeMessage(e.Result, now)
		}
	case "assistant":
		if len(e.Message.Content) == 1 && e.Message.Content[0].Type == "text" {
			return claudeMessage(e.Message.Content[0].Text, now)
		}
	}

	return nil
}

// claudeEvent reads the windows of unifiedWindows, and backs them up with the
// top-level status, reset time and use. The top level applies to the window
// that rateLimitType names, or, with no rateLimitType, to the window that its
// reset time gives. The top-level reading fills a window that unifiedWindows
// does not complete.
func claudeEvent(raw json.RawMessage, now time.Time) []Reading {
	var info struct {
		Status         string                  `json:"status"`
		ResetsAt       int64                   `json:"resetsAt"`
		Utilization    *float64                `json:"utilization"`
		RateLimitType  string                  `json:"rateLimitType"`
		UnifiedWindows map[string]claudeWindow `json:"unifiedWindows"`
	}
	if json.Unmarshal(raw, &info) != nil {
		return nil
	}
	rejected := info.Status == "rejected"
	status, hasStatus := claudeStatus(info.Status, info.ResetsAt, info.Utilization, info.RateLimitType, now)
	var out []Reading
	read := map[string]bool{}
	for key, w := range info.UnifiedWindows {
		name, known := claudeWindows[key]
		if !known {
			continue
		}
		top := key == info.RateLimitType || (info.RateLimitType == "" && hasStatus && status.Window == name)
		if unixTime(w.ResetsAt) == nil && top {
			w.ResetsAt = info.ResetsAt
		}
		if w.Utilization == nil && top {
			w.Utilization = info.Utilization
		}
		if unixTime(w.ResetsAt) == nil {
			continue
		}
		r := Reading{
			Window:   name,
			ResetsAt: unixTime(w.ResetsAt),
			Limited:  w.Status == "rejected" || (rejected && top),
			Source:   SourceClaudeEvent,
			ReadAt:   now,
		}
		if w.Utilization != nil {
			r.UsedPercent = percent(fromFraction(*w.Utilization))
		}
		out = append(out, r)
		read[name] = true
	}
	if hasStatus && !read[status.Window] {
		out = append(out, status)
	}

	return out
}

// claudeStatus reads the top-level status and reset time of an event as one
// window. The window comes from rateLimitType, or else from the reset time.
func claudeStatus(status string, resetsAt int64, utilization *float64, rateLimitType string, now time.Time) (Reading, bool) {
	name := claudeWindows[rateLimitType]
	resets := unixTime(resetsAt)
	switch {
	case resets == nil:
		return Reading{}, false
	case name != "":
	case resets.Sub(now) <= sessionWindow:
		name = "session"
	default:
		name = "week"
	}
	r := Reading{Window: name, ResetsAt: resets, Limited: status == "rejected", Source: SourceClaudeStatus, ReadAt: now}
	if utilization != nil {
		r.UsedPercent = percent(fromFraction(*utilization))
	}

	return r, true
}

var (
	limitMessage = regexp.MustCompile(`^You[’']ve hit your (session|weekly|Opus|Sonnet) limit\s*[·•|-]\s*resets\s+(.+)$`)
	clockTime    = regexp.MustCompile(`(?i)(\d{1,2})(?::(\d{2}))?\s*(am|pm)`)
	monthDay     = regexp.MustCompile(`(?i)\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2})\b`)
	weekday      = regexp.MustCompile(`(?i)\b(sun|mon|tue|wed|thu|fri|sat)[a-z]*\b`)
)

var months = map[string]time.Month{
	"jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6,
	"jul": 7, "aug": 8, "sep": 9, "oct": 10, "nov": 11, "dec": 12,
}

var weekdays = map[string]time.Weekday{
	"sun": time.Sunday, "mon": time.Monday, "tue": time.Tuesday, "wed": time.Wednesday,
	"thu": time.Thursday, "fri": time.Friday, "sat": time.Saturday,
}

// ParseLimitMessage reads a text that is a usage limit message, such as the
// output of a run that stopped at its limit. now gives the time zone of a
// reset time that the message writes as a clock time.
func ParseLimitMessage(text string, now time.Time) []Reading {
	return claudeMessage(text, now)
}

// IsLimitMessage reports whether the whole text is a usage limit message.
func IsLimitMessage(text string) bool {
	return limitMessage.MatchString(strings.TrimSpace(text))
}

// claudeMessage reads the whole text of a usage limit message. A text that
// only holds such a message in a longer one, such as a quote, gives nothing.
func claudeMessage(text string, now time.Time) []Reading {
	m := limitMessage.FindStringSubmatch(strings.TrimSpace(text))
	if m == nil {
		return nil
	}
	window := "week"
	if m[1] == "session" {
		window = "session"
	}
	r := Reading{Window: window, Limited: true, Source: SourceClaudeMessage, ReadAt: now}
	if t, ok := resetTime(m[2], now); ok {
		r.ResetsAt = &t
	}

	return []Reading{r}
}

// resetTime reads the reset time of a message in the time zone of now, as its
// next occurrence after now. A month and day, or a weekday, narrow the day.
func resetTime(text string, now time.Time) (time.Time, bool) {
	c := clockTime.FindStringSubmatch(text)
	if c == nil {
		return time.Time{}, false
	}
	hour, _ := strconv.Atoi(c[1])
	minute, _ := strconv.Atoi(c[2])
	if hour < 1 || hour > 12 || minute > 59 {
		return time.Time{}, false
	}
	hour %= 12
	if strings.EqualFold(c[3], "pm") {
		hour += 12
	}
	loc := now.Location()
	at := func(y int, mo time.Month, d int) time.Time { return time.Date(y, mo, d, hour, minute, 0, 0, loc) }

	if md := monthDay.FindStringSubmatch(text); md != nil {
		day, _ := strconv.Atoi(md[2])
		t := at(now.Year(), months[strings.ToLower(md[1])], day)
		if !t.After(now) {
			t = at(now.Year()+1, months[strings.ToLower(md[1])], day)
		}

		return t, true
	}
	t := at(now.Year(), now.Month(), now.Day())
	if wd := weekday.FindStringSubmatch(text); wd != nil {
		for i := 0; i < 8; i++ {
			next := t.AddDate(0, 0, i)
			if next.Weekday() == weekdays[strings.ToLower(wd[1])] && next.After(now) {
				return next, true
			}
		}
	}
	if !t.After(now) {
		t = at(now.Year(), now.Month(), now.Day()+1)
	}

	return t, true
}

// fromFraction turns a use that Claude Code gives as a fraction into percent,
// and rounds away the error of the float product, such as 28.999999999999996.
func fromFraction(f float64) float64 {
	return math.Round(f*100*1e4) / 1e4
}
