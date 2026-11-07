package cmd

import (
	"bytes"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/zalando/go-keyring"

	"github.com/loupe-ac/loupe/cli/internal/config"
)

const (
	wakeProject = "01a007cc-0000-7000-8000-00000000000a"
	wakeSession = "0199a0e2-0000-7000-8000-00000000010"
)

func logLine(time, event, card, rule, session string) string {
	line := `{"time":"` + time + `","level":"INFO","event":"` + event + `","card":` + card + `,"project":"` + wakeProject + `","rule":"` + rule
	if session != "" {
		line += `","session_id":"` + session
	}

	return line + `"}`
}

func assistantAt(at, id string, input, read, write string) string {
	return `{"type":"assistant","timestamp":"` + at + `","message":{"id":"` + id + `","model":"claude-opus-5-5","usage":{"input_tokens":` + input +
		`,"output_tokens":1,"cache_read_input_tokens":` + read + `,"cache_creation_input_tokens":` + write + `}}}`
}

// wakeFixture writes a bridge log with three sessions and their transcripts.
// Session 1 has two pauses: 30 minutes, then 89 minutes. Both are cold.
// Session 2 has a pause of 3 minutes, and session 3 never resumed.
func wakeFixture(t *testing.T) string {
	t.Helper()
	dir := claudeHome(t, "")
	s1, s2, s3 := wakeSession+"1", wakeSession+"2", wakeSession+"3"
	writeTranscript(t, dir, s1,
		assistantAt("2026-09-20T10:00:02Z", "a", "1", "2", "3"),
		assistantAt("2026-09-20T10:30:02Z", "b", "2", "100", "900"),
		assistantAt("2026-09-20T10:30:03Z", "b", "7", "300", "500"),
		assistantAt("2026-09-20T10:31:00Z", "c", "1", "1", "1"),
		assistantAt("2026-09-20T12:00:05Z", "d", "3", "4", "50"),
	)
	writeTranscript(t, dir, s2,
		assistantAt("2026-09-20T09:00:02Z", "e", "1", "2", "3"),
		assistantAt("2026-09-20T09:03:02Z", "f", "5", "900", "10"),
	)
	writeTranscript(t, dir, s3, assistantAt("2026-09-20T08:00:02Z", "g", "1", "2", "3"))
	log := strings.Join([]string{
		logLine("2026-09-20T10:00:00Z", "worker_started", "1", "plan", s1),
		logLine("2026-09-20T10:00:30Z", "worker_finished", "1", "plan", ""),
		logLine("2026-09-20T10:30:00Z", "worker_started", "1", "plan", s1),
		logLine("2026-09-20T10:45:00Z", "worker_finished", "1", "plan", ""),
		logLine("2026-09-20T12:00:00Z", "worker_started", "1", "plan", s1),
		logLine("2026-09-20T12:10:00Z", "worker_finished", "1", "plan", ""),
		logLine("2026-09-20T09:00:00Z", "worker_started", "2", "plan", s2),
		logLine("2026-09-20T09:01:00Z", "worker_finished", "2", "plan", ""),
		logLine("2026-09-20T09:03:00Z", "worker_started", "2", "plan", s2),
		logLine("2026-09-20T09:04:00Z", "worker_finished", "2", "plan", ""),
		logLine("2026-09-20T08:00:00Z", "worker_started", "3", "plan", s3),
		logLine("2026-09-20T08:01:00Z", "worker_finished", "3", "plan", ""),
	}, "\n") + "\n"
	path := filepath.Join(t.TempDir(), "bridge.log")
	if err := os.WriteFile(path, []byte(log), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func runWakes(t *testing.T, log string, args ...string) (string, error) {
	t.Helper()
	cmd := newUsageCmd()
	var out bytes.Buffer
	cmd.SetArgs(append([]string{"backfill-wakes", "--log-file", log}, args...))
	cmd.SetOut(&out)
	cmd.SetErr(&bytes.Buffer{})
	cmd.SilenceUsage, cmd.SilenceErrors = true, true
	err := cmd.Execute()

	return out.String(), err
}

func TestBackfillWakesDryRunCountsTheBuckets(t *testing.T) {
	log := wakeFixture(t)
	keyring.MockInit()
	shortConfigHome(t)

	out, err := runWakes(t, log, "--dry-run")
	if err != nil {
		t.Fatal(err)
	}

	want := strings.Join([]string{
		"would send " + wakeSession + "1 (card 1): 2 wakes",
		"would send " + wakeSession + "2 (card 2): 1 wakes",
		"skipped " + wakeSession + "3 (card 3): no process of the session resumed a pause",
		"pauses under 5m: 1, cold 0",
		"pauses 5m-1h: 1, cold 1",
		"pauses over 1h: 1, cold 1",
	}, "\n") + "\n"
	if out != want {
		t.Fatalf("output:\n%s\nwant:\n%s", out, want)
	}
}

func TestBackfillWakesSendsOneRequestForEachSession(t *testing.T) {
	log := wakeFixture(t)
	keyring.MockInit()
	shortConfigHome(t)
	got := &recordedRequests{bodies: map[string]string{}}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		got.add(r.Method+" "+r.URL.Path, string(body))
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)
	if err := config.Save(testLogin(server.URL)); err != nil {
		t.Fatal(err)
	}

	out, err := runWakes(t, log)
	if err != nil {
		t.Fatal(err)
	}

	if !strings.Contains(out, "sent "+wakeSession+"1 (card 1): 2 wakes\n") || len(got.order) != 2 {
		t.Fatalf("output:\n%s\nrequests: %v", out, got.order)
	}
	body := got.bodies["PUT /api/v1/bridge/projects/"+wakeProject+"/worker-runs/sessions/"+wakeSession+"1/wake"]
	want := `{"wakes":[null,` +
		`{"trigger":"backfill","lastTurnAt":"2026-09-20T10:00:02Z","firstTurn":{"inputTokens":7,"cacheReadTokens":300,"cacheWriteTokens":900}},` +
		`{"trigger":"backfill","lastTurnAt":"2026-09-20T10:31:00Z","firstTurn":{"inputTokens":3,"cacheReadTokens":4,"cacheWriteTokens":50}}]}`
	if body != want {
		t.Fatalf("body = %s\nwant   %s", body, want)
	}
}

// A refused session does not stop the others, and the command then fails.
func TestBackfillWakesGoesOnAfterAFailure(t *testing.T) {
	log := wakeFixture(t)
	keyring.MockInit()
	shortConfigHome(t)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.Contains(r.URL.Path, wakeSession+"1") {
			w.WriteHeader(http.StatusNotFound)

			return
		}
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)
	if err := config.Save(testLogin(server.URL)); err != nil {
		t.Fatal(err)
	}

	out, err := runWakes(t, log)

	if err == nil || err.Error() != "1 of the sessions failed" {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(out, "failed "+wakeSession+"1 (card 1)") || !strings.Contains(out, "sent "+wakeSession+"2 (card 2): 1 wakes\n") {
		t.Fatalf("output:\n%s", out)
	}
}
