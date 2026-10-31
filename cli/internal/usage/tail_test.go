package usage

import (
	"os"
	"path/filepath"
	"strconv"
	"testing"
	"time"
)

func appendTo(t *testing.T, path, text string) {
	t.Helper()
	f, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	if _, err := f.WriteString(text); err != nil {
		t.Fatal(err)
	}
}

const eventLine = `{"type":"rate_limit_event","rate_limit_info":{"unifiedWindows":{"five_hour":{"utilization":0.3,"resetsAt":1793420400}}}}`

func TestTailReadsOnlyNewLines(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stdout")
	tail := NewTail(func() (string, bool) { return path, true }, ParseClaude)

	if got := tail.Read(now, false); len(got) != 0 {
		t.Fatalf("missing file = %v", got)
	}
	appendTo(t, path, eventLine+"\n")
	if got := tail.Read(now, false); len(got) != 1 {
		t.Fatalf("first read = %v", got)
	}
	if got := tail.Read(now, false); len(got) != 0 {
		t.Fatalf("second read = %v", got)
	}
	appendTo(t, path, eventLine+"\n")
	if got := tail.Read(now, false); len(got) != 1 {
		t.Fatalf("third read = %v", got)
	}
}

func TestTailWaitsForTheEndOfALine(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stdout")
	tail := NewTail(func() (string, bool) { return path, true }, ParseClaude)
	appendTo(t, path, eventLine[:40])
	if got := tail.Read(now, false); len(got) != 0 {
		t.Fatalf("torn line = %v", got)
	}
	appendTo(t, path, eventLine[40:]+"\n")
	if got := tail.Read(now, false); len(got) != 1 {
		t.Fatalf("whole line = %v", got)
	}
}

func TestTailFinalReadTakesALineWithNoNewline(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stdout")
	tail := NewTail(func() (string, bool) { return path, true }, ParseClaude)
	appendTo(t, path, eventLine)
	if got := tail.Read(now, true); len(got) != 1 {
		t.Fatalf("final read = %v", got)
	}
}

func TestTailFindsItsFileLate(t *testing.T) {
	path := filepath.Join(t.TempDir(), "session")
	found := false
	tail := NewTail(func() (string, bool) { return path, found }, ParseClaude)
	appendTo(t, path, eventLine+"\n")
	if got := tail.Read(now, false); len(got) != 0 {
		t.Fatalf("unfound = %v", got)
	}
	found = true
	if got := tail.Read(now, false); len(got) != 1 {
		t.Fatalf("found = %v", got)
	}
}

func TestCollectorKeepsLatestWindowAndDrops(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stdout")
	c := NewCollector()
	c.Start("run1", "claude", NewTail(func() (string, bool) { return path, true }, ParseClaude))

	appendTo(t, path, `{"type":"rate_limit_event","rate_limit_info":{"unifiedWindows":{"five_hour":{"utilization":0.1,"resetsAt":1793420400}}}}`+"\n")
	c.Poll(now)
	appendTo(t, path, `{"type":"rate_limit_event","rate_limit_info":{"unifiedWindows":{"five_hour":{"utilization":0.2,"resetsAt":1793420400}}}}`+"\n")
	c.Poll(now.Add(time.Minute))

	got := c.Pending()
	rs := got["claude"]
	if len(rs) != 1 || pct(t, rs[0]) != 20 {
		t.Fatalf("pending = %+v", got)
	}

	appendTo(t, path, `{"type":"rate_limit_event","rate_limit_info":{"unifiedWindows":{"five_hour":{"utilization":0.4,"resetsAt":1793420400}}}}`+"\n")
	c.Poll(now.Add(2 * time.Minute))
	c.Drop(got)
	rest := c.Pending()["claude"]
	if len(rest) != 1 || pct(t, rest[0]) != 40 {
		t.Fatalf("after drop = %+v", rest)
	}

	c.Drop(c.Pending())
	if len(c.Pending()) != 0 {
		t.Fatalf("not empty: %+v", c.Pending())
	}
}

func TestCollectorFinishReadsTheRest(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stdout")
	c := NewCollector()
	c.Start("run1", "claude", NewTail(func() (string, bool) { return path, true }, ParseClaude))
	appendTo(t, path, eventLine+"\n")
	c.Finish("run1", now)
	if len(c.Pending()["claude"]) != 1 {
		t.Fatalf("pending = %+v", c.Pending())
	}
	c.Poll(now)
	c.Finish("unknown", now)
}

func TestCollectorDropsAReadingThatLoupeRefuses(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stdout")
	c := NewCollector()
	c.Start("run1", "claude", NewTail(func() (string, bool) { return path, true }, ParseClaude))
	appendTo(t, path, `{"type":"result","is_error":true,"result":"You've hit your session limit · resets soon"}`+"\n")
	appendTo(t, path, `{"type":"result","is_error":true,"result":"You've hit your weekly limit · resets Oct 30, 3pm"}`+"\n")
	c.Finish("run1", now)
	if got := c.Pending(); len(got) != 0 {
		t.Fatalf("pending = %+v", got)
	}

	codex := filepath.Join(t.TempDir(), "session.jsonl")
	c.Start("run2", "codex", NewTail(func() (string, bool) { return codex, true }, ParseCodex))
	appendTo(t, codex, `{"payload":{"rate_limits":{"primary":{"used_percent":10,"window_minutes":-60,"resets_at":1793420400},"secondary":{"used_percent":20,"window_minutes":10080,"resets_at":0}}}}`+"\n")
	c.Finish("run2", now)
	if got := c.Pending(); len(got) != 0 {
		t.Fatalf("pending = %+v", got)
	}
}

func TestCollectorKeepsAResetAtTheLimitOfTheLead(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stdout")
	c := NewCollector()
	c.Start("run1", "claude", NewTail(func() (string, bool) { return path, true }, ParseClaude))
	limit := now.Add(maxResetLead).Unix()
	appendTo(t, path, `{"type":"rate_limit_event","rate_limit_info":{"unifiedWindows":{"seven_day":{"utilization":0.1,"resetsAt":`+strconv.FormatInt(limit, 10)+`}}}}`+"\n")
	c.Finish("run1", now)
	if rs := c.Pending()["claude"]; len(rs) != 1 || rs[0].ResetsAt.Unix() != limit {
		t.Fatalf("pending = %+v", c.Pending())
	}
}
