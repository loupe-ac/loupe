package usage

import (
	"bufio"
	"os"
	"path/filepath"
	"strconv"
	"testing"
	"time"
)

var now = time.Date(2026, 10, 31, 10, 0, 0, 0, time.UTC)

func readAll(t *testing.T, name string, parse Parser) []Reading {
	t.Helper()
	f, err := os.Open(filepath.Join("testdata", name))
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	var out []Reading
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		out = append(out, parse(sc.Bytes(), now)...)
	}

	return out
}

func itoa(n int64) string { return strconv.FormatInt(n, 10) }

func byWindow(rs []Reading) map[string]Reading {
	m := map[string]Reading{}
	for _, r := range rs {
		m[r.Window] = r
	}

	return m
}

func pct(t *testing.T, r Reading) float64 {
	t.Helper()
	if r.UsedPercent == nil {
		t.Fatalf("%s: usedPercent is null", r.Window)
	}

	return *r.UsedPercent
}

func TestClaudeUnifiedWindows(t *testing.T) {
	got := byWindow(readAll(t, "claude_event_windows.jsonl", ParseClaude))
	if len(got) != 2 {
		t.Fatalf("windows = %v", got)
	}
	s, w := got["session"], got["week"]
	if pct(t, s) != 29 || pct(t, w) != 26 {
		t.Errorf("percent = %v, %v", pct(t, s), pct(t, w))
	}
	if s.Source != SourceClaudeEvent || s.Limited || s.ResetsAt == nil || s.ResetsAt.Unix() != 1793420400 {
		t.Errorf("session = %+v", s)
	}
	if !s.ReadAt.Equal(now) {
		t.Errorf("readAt = %v", s.ReadAt)
	}
}

func TestClaudeStatusWithoutUtilization(t *testing.T) {
	got := readAll(t, "claude_event_status.jsonl", ParseClaude)
	if len(got) != 1 {
		t.Fatalf("readings = %v", got)
	}
	r := got[0]
	if r.Window != "session" || r.UsedPercent != nil || r.Limited || r.Source != SourceClaudeStatus || r.ResetsAt.Unix() != 1793420400 {
		t.Errorf("reading = %+v", r)
	}
}

func TestClaudeRejected(t *testing.T) {
	got := readAll(t, "claude_event_rejected.jsonl", ParseClaude)
	if len(got) != 1 {
		t.Fatalf("readings = %v", got)
	}
	r := got[0]
	if r.Window != "week" || !r.Limited || pct(t, r) != 100 {
		t.Errorf("reading = %+v", r)
	}
}

func TestClaudeStatusWindowFromResetTime(t *testing.T) {
	soon := `{"type":"rate_limit_event","rate_limit_info":{"status":"rejected","resetsAt":` + itoa(now.Add(4*time.Hour).Unix()) + `}}`
	later := `{"type":"rate_limit_event","rate_limit_info":{"status":"rejected","resetsAt":` + itoa(now.Add(30*time.Hour).Unix()) + `}}`
	if got := ParseClaude([]byte(soon), now); len(got) != 1 || got[0].Window != "session" {
		t.Errorf("soon = %+v", got)
	}
	if got := ParseClaude([]byte(later), now); len(got) != 1 || got[0].Window != "week" {
		t.Errorf("later = %+v", got)
	}
}

func TestClaudeBareEventReadsNothing(t *testing.T) {
	if got := ParseClaude([]byte(`{"type":"rate_limit_event","uuid":"x"}`), now); len(got) != 0 {
		t.Errorf("readings = %+v", got)
	}
}

func TestClaudeLimitMessage(t *testing.T) {
	got := readAll(t, "claude_message.jsonl", ParseClaude)
	if len(got) != 2 {
		t.Fatalf("readings = %v", got)
	}
	for _, r := range got {
		want := time.Date(2026, 10, 31, 15, 45, 0, 0, time.UTC)
		if r.Window != "session" || !r.Limited || r.UsedPercent != nil || r.Source != SourceClaudeMessage || r.ResetsAt == nil || !r.ResetsAt.Equal(want) {
			t.Errorf("reading = %+v", r)
		}
	}
}

func TestClaudeLimitMessageReadsNextOccurrenceInLocalZone(t *testing.T) {
	paris := time.FixedZone("CET", 3600)
	at := time.Date(2026, 10, 31, 17, 0, 0, 0, paris)
	line := `{"type":"result","is_error":true,"result":"You've hit your session limit · resets 3:45pm"}`
	got := ParseClaude([]byte(line), at)
	want := time.Date(2026, 11, 1, 15, 45, 0, 0, paris)
	if len(got) != 1 || !got[0].ResetsAt.Equal(want) {
		t.Errorf("got %+v, want reset %v", got, want)
	}
}

func TestClaudeLimitMessageWindows(t *testing.T) {
	for text, window := range map[string]string{
		"You've hit your weekly limit · resets 9am":  "week",
		"You've hit your Opus limit · resets 9am":    "week",
		"You've hit your Sonnet limit · resets 9am":  "week",
		"You've hit your session limit · resets 9am": "session",
	} {
		line := `{"type":"result","is_error":true,"result":"` + text + `"}`
		got := ParseClaude([]byte(line), now)
		if len(got) != 1 || got[0].Window != window {
			t.Errorf("%q = %+v, want %s", text, got, window)
		}
	}
}

func TestClaudeIgnoresQuotedMessage(t *testing.T) {
	quoted := `{"type":"assistant","message":{"content":[{"type":"text","text":"The docs say: You've hit your session limit · resets 3:45pm, and then we retry."}]}}`
	ok := `{"type":"result","is_error":false,"result":"You've hit your session limit · resets 3:45pm"}`
	spend := `{"type":"result","is_error":true,"result":"You've hit your monthly spend limit"}`
	for _, l := range []string{quoted, ok, spend} {
		if got := ParseClaude([]byte(l), now); len(got) != 0 {
			t.Errorf("%s = %+v", l, got)
		}
	}
}

func TestClaudeIgnoresBrokenLines(t *testing.T) {
	for _, l := range []string{"", "not json", `{"type":"rate_limit_event"`, `{"type":"rate_limit_event","rate_limit_info":"x"}`} {
		if got := ParseClaude([]byte(l), now); len(got) != 0 {
			t.Errorf("%q = %+v", l, got)
		}
	}
}

func TestClaudeClampsUtilization(t *testing.T) {
	line := `{"type":"rate_limit_event","rate_limit_info":{"unifiedWindows":{"five_hour":{"utilization":3.5,"resetsAt":1793420400}}}}`
	got := ParseClaude([]byte(line), now)
	if len(got) != 1 || pct(t, got[0]) != 200 {
		t.Errorf("got %+v", got)
	}
}

func TestCodexSession(t *testing.T) {
	got := byWindow(readAll(t, "codex_session.jsonl", ParseCodex))
	if len(got) != 2 {
		t.Fatalf("windows = %v", got)
	}
	s, w := got["session"], got["week"]
	if pct(t, s) != 29 || pct(t, w) != 12.5 {
		t.Errorf("percent = %v, %v", pct(t, s), pct(t, w))
	}
	if s.Source != SourceCodex || s.Limited || s.ResetsAt.Unix() != 1793105206 {
		t.Errorf("session = %+v", s)
	}
	if !s.ReadAt.Equal(time.Date(2026, 10, 20, 13, 49, 46, 375000000, time.UTC)) {
		t.Errorf("readAt = %v", s.ReadAt)
	}
}

func TestClaudeAndCodexAgreeOnTwentyNine(t *testing.T) {
	c := byWindow(readAll(t, "claude_event_windows.jsonl", ParseClaude))["session"]
	x := byWindow(readAll(t, "codex_session.jsonl", ParseCodex))["session"]
	if pct(t, c) != 29 || pct(t, x) != 29 {
		t.Errorf("claude %v, codex %v", pct(t, c), pct(t, x))
	}
}

func TestCodexReached(t *testing.T) {
	got := byWindow(readAll(t, "codex_reached.jsonl", ParseCodex))
	if !got["session"].Limited || got["week"].Limited {
		t.Errorf("got %+v", got)
	}
}

func TestCodexWindowNames(t *testing.T) {
	for minutes, want := range map[int]string{300: "session", 10080: "week", 180: "3h", 4320: "3d", 90: "90m"} {
		if got := windowName(minutes); got != want {
			t.Errorf("windowName(%d) = %s, want %s", minutes, got, want)
		}
	}
}

func TestCodexIgnoresOtherLimitAndNoLimits(t *testing.T) {
	other := `{"timestamp":"2026-10-20T13:49:46Z","type":"event_msg","payload":{"type":"token_count","rate_limits":{"limit_id":"codex_other","primary":{"used_percent":9,"window_minutes":300,"resets_at":1793105206}}}}`
	none := `{"timestamp":"2026-10-20T13:49:46Z","type":"event_msg","payload":{"type":"token_count","rate_limits":null}}`
	for _, l := range []string{other, none, "garbage"} {
		if got := ParseCodex([]byte(l), now); len(got) != 0 {
			t.Errorf("%s = %+v", l, got)
		}
	}
}

func TestClaudeTopLevelBacksUpIncompleteWindows(t *testing.T) {
	line := `{"type":"rate_limit_event","rate_limit_info":{"status":"rejected","resetsAt":1793420400,"rateLimitType":"five_hour","utilization":0.9,` +
		`"unifiedWindows":{"five_hour":{"status":"rejected"},"seven_day":{"utilization":0.2}}}}`
	got := ParseClaude([]byte(line), now)
	if len(got) != 1 {
		t.Fatalf("readings = %+v", got)
	}
	s := got[0]
	if s.Window != "session" || !s.Limited || s.Source != SourceClaudeEvent || s.ResetsAt == nil || s.ResetsAt.Unix() != 1793420400 || pct(t, s) != 90 {
		t.Errorf("session = %+v", s)
	}
}

func TestClaudeTopLevelFillsAWindowThatUnifiedWindowsLacks(t *testing.T) {
	line := `{"type":"rate_limit_event","rate_limit_info":{"status":"rejected","resetsAt":` + itoa(now.Add(30*time.Hour).Unix()) + `,"rateLimitType":"seven_day",` +
		`"unifiedWindows":{"five_hour":{"utilization":0.3,"resetsAt":1793420400}}}}`
	got := byWindow(ParseClaude([]byte(line), now))
	if len(got) != 2 {
		t.Fatalf("windows = %+v", got)
	}
	if s := got["session"]; s.Limited || s.Source != SourceClaudeEvent || pct(t, s) != 30 {
		t.Errorf("session = %+v", s)
	}
	if w := got["week"]; !w.Limited || w.Source != SourceClaudeStatus || w.UsedPercent != nil {
		t.Errorf("week = %+v", w)
	}
}

func TestClaudeCompleteWindowWinsOverTopLevel(t *testing.T) {
	line := `{"type":"rate_limit_event","rate_limit_info":{"status":"allowed","resetsAt":1793430000,"rateLimitType":"five_hour",` +
		`"unifiedWindows":{"five_hour":{"utilization":0.3,"resetsAt":1793420400}}}}`
	got := ParseClaude([]byte(line), now)
	if len(got) != 1 || got[0].Source != SourceClaudeEvent || got[0].ResetsAt.Unix() != 1793420400 {
		t.Fatalf("readings = %+v", got)
	}
}
