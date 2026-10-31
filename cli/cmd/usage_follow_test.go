package cmd

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"

	harn "github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/harness/claude"
	"github.com/loupe-ac/loupe/cli/internal/harness/codex"
	"github.com/loupe-ac/loupe/cli/internal/usage"
)

func appendFile(t *testing.T, path, text string) {
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

func TestARunReportsTheUsageOfItsAccountWhileItRunsAndAfterItEnds(t *testing.T) {
	h := newHarness(t)
	h.router.accountUsage = usage.NewCollector()
	dir := t.TempDir()
	stdout := filepath.Join(dir, "stdout")
	started, release := make(chan struct{}), make(chan struct{})
	h.router.worker.run = func(_ context.Context, _ workerSpec, onStart func(workerProc)) workerResult {
		appendFile(t, stdout, usageEvent)
		onStart(workerProc{dir: dir})
		close(started)
		<-release
		// The last line has no newline, as when the process stops in the middle of a write.
		appendFile(t, stdout, `{"type":"result","is_error":true,"result":"You've hit your weekly limit · resets 9am"}`)

		return unfinishedRun
	}

	h.router.onData([]byte(cardMoved(87)))
	<-started
	h.router.accountUsage.Poll(time.Now())
	got := h.router.accountUsage.Pending()["claude"]
	if len(got) != 2 || got[0].Window != "session" || got[1].Window != "week" || got[1].Limited {
		t.Fatalf("readings while the run goes = %+v", got)
	}

	close(release)
	h.router.wg.Wait()
	got = h.router.accountUsage.Pending()["claude"]
	if len(got) != 2 || got[1].Source != usage.SourceClaudeMessage || !got[1].Limited {
		t.Fatalf("readings after the run = %+v", got)
	}
}

func TestUsageTailPicksTheFileOfTheHarness(t *testing.T) {
	dir := t.TempDir()
	appendFile(t, filepath.Join(dir, "stdout"), usageEvent)
	got := usageTail(claude.Harness{}, dir, time.Time{}).Read(time.Now(), true)
	if len(got) != 2 {
		t.Fatalf("claude readings = %+v", got)
	}

	home := t.TempDir()
	appendFile(t, filepath.Join(dir, "stdout"), `{"type":"thread.started","thread_id":"01a11b44-0eb9-71f3-b3f1-3066d6b81ee6"}`+"\n")
	session := filepath.Join(home, "sessions", "2026", "10", "20", "rollout-x-01a11b44-0eb9-71f3-b3f1-3066d6b81ee6.jsonl")
	if err := os.MkdirAll(filepath.Dir(session), 0o700); err != nil {
		t.Fatal(err)
	}
	old := `{"timestamp":"2026-10-20T13:00:00Z","type":"event_msg","payload":{"type":"token_count","rate_limits":{"primary":{"used_percent":90,"window_minutes":300,"resets_at":1793105206}}}}` + "\n"
	fresh := `{"timestamp":"2026-10-20T14:00:00Z","type":"event_msg","payload":{"type":"token_count","rate_limits":{"primary":{"used_percent":29,"window_minutes":300,"resets_at":1793105206}}}}` + "\n"
	appendFile(t, session, old+fresh)
	since := time.Date(2026, 10, 20, 13, 30, 0, 0, time.UTC)
	var h harn.Harness = codex.New(home, "", "")
	got = usageTail(h, dir, since).Read(time.Now(), true)
	if len(got) != 1 || *got[0].UsedPercent != 29 {
		t.Fatalf("codex readings = %+v", got)
	}
}
