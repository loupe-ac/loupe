package cmd

import (
	"bytes"
	"context"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/logfile"
)

func writeLines(t *testing.T, path string, lines ...string) {
	t.Helper()
	data := strings.Join(lines, "\n") + "\n"
	if err := os.WriteFile(path, []byte(data), 0o600); err != nil {
		t.Fatal(err)
	}
}

func runLogs(t *testing.T, args ...string) (string, error) {
	t.Helper()
	cmd := newBridgeLogsCmd()
	var out bytes.Buffer
	cmd.SetArgs(args)
	cmd.SetOut(&out)
	cmd.SetErr(&bytes.Buffer{})
	cmd.SilenceUsage, cmd.SilenceErrors = true, true
	err := cmd.Execute()

	return out.String(), err
}

func TestLogfmtPutsTimeLevelAndEventFirst(t *testing.T) {
	line := `{"count":3,"time":"2026-10-30T10:00:00Z","note":"two words","level":"INFO","event":"worker_started","empty":"","nested":{"a":1},"ok":true}`
	want := `time=2026-10-30T10:00:00Z level=INFO event=worker_started count=3 note="two words" empty="" nested="{\"a\":1}" ok=true`
	if got := logfmt([]byte(line)); got != want {
		t.Fatalf("logfmt =\n%s\nwant\n%s", got, want)
	}
	if got := logfmt([]byte("not json")); got != "not json" {
		t.Fatalf("a line that is not JSON changed: %q", got)
	}
}

func TestBridgeLogsMergesTheDatedFilesAndTheLiveFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bridge.log")
	writeLines(t, logfile.DatedPath(path, time.Date(2026, 10, 29, 0, 0, 0, 0, time.Local)),
		`{"time":"2026-10-29T10:00:00Z","level":"INFO","event":"one","bridge":"a"}`,
		`{"time":"2026-10-29T11:00:00Z","level":"INFO","event":"two","bridge":"b"}`)
	writeLines(t, path,
		`{"time":"2026-10-30T09:00:00Z","level":"WARN","event":"three"}`,
		`{"time":"2026-10-30T09:30:00Z","level":"INFO","event":"four","bridge":"a"}`)

	out, err := runLogs(t, "--log-file", path, "-n", "3")
	if err != nil {
		t.Fatal(err)
	}
	want := "time=2026-10-29T11:00:00Z level=INFO event=two bridge=b\n" +
		"time=2026-10-30T09:00:00Z level=WARN event=three\n" +
		"time=2026-10-30T09:30:00Z level=INFO event=four bridge=a\n"
	if out != want {
		t.Fatalf("output:\n%s\nwant:\n%s", out, want)
	}

	out, err = runLogs(t, "--log-file", path, "--bridge", "a", "--json")
	if err != nil {
		t.Fatal(err)
	}
	want = `{"time":"2026-10-29T10:00:00Z","level":"INFO","event":"one","bridge":"a"}` + "\n" +
		`{"time":"2026-10-30T09:30:00Z","level":"INFO","event":"four","bridge":"a"}` + "\n"
	if out != want {
		t.Fatalf("--bridge a --json:\n%s\nwant:\n%s", out, want)
	}
}

func TestBridgeLogsFindsTheLogOfAStoppedBridge(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	logPath := filepath.Join(dir, "elsewhere.log")
	writeLines(t, logPath, `{"time":"2026-10-30T09:00:00Z","level":"INFO","event":"bridge_stopped","bridge":"mac"}`)
	statePath, err := statePath("rules.yaml")
	if err != nil {
		t.Fatal(err)
	}
	if err := writeBridgeState(statePath, bridgeState{Name: "mac", LogFile: logPath}); err != nil {
		t.Fatal(err)
	}

	out, err := runLogs(t)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "event=bridge_stopped") {
		t.Fatalf("output = %q", out)
	}
}

func TestBridgeLogsFailsWithoutALog(t *testing.T) {
	shortConfigHome(t)
	if _, err := runLogs(t); err == nil {
		t.Fatal("want an error when no log exists")
	}
}

func TestLastLinesReadsBackwardAcrossBlocks(t *testing.T) {
	var b bytes.Buffer
	for i := range 5000 {
		b.WriteString(strings.Repeat("x", 40))
		b.WriteString(string(rune('a' + i%26)))
		b.WriteByte('\n')
	}
	got, err := lastLines(bytes.NewReader(b.Bytes()), int64(b.Len()), 3)
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 3 || string(got[2]) != strings.Repeat("x", 40)+string(rune('a'+4999%26)) {
		t.Fatalf("lines = %q", got)
	}
	all, _ := lastLines(bytes.NewReader([]byte("a\nb\n")), 4, 10)
	if len(all) != 2 || string(all[0]) != "a" {
		t.Fatalf("short file lines = %q", all)
	}
}

// -f must lose nothing and repeat nothing when the log rotates between two polls.
func TestFollowerReadsTheOldFileToItsEndBeforeTheNewOne(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bridge.log")
	writeLines(t, path, `{"event":"old1"}`)
	f := openFollower(path)

	appendTo := func(p, s string) {
		t.Helper()
		fh, err := os.OpenFile(p, os.O_APPEND|os.O_WRONLY|os.O_CREATE, 0o600)
		if err != nil {
			t.Fatal(err)
		}
		fh.WriteString(s)
		fh.Close()
	}
	events := func(es []logEntry) string {
		var names []string
		for _, e := range es {
			names = append(names, strings.TrimSuffix(strings.TrimPrefix(string(e.line), `{"event":"`), `"}`))
		}

		return strings.Join(names, ",")
	}

	appendTo(path, `{"event":"old2"}`+"\n"+`{"event":"hal`)
	if got := events(f.poll()); got != "old2" {
		t.Fatalf("first poll = %q", got)
	}
	appendTo(path, `f"}`+"\n")
	if err := os.Rename(path, logfile.DatedPath(path, time.Now())); err != nil {
		t.Fatal(err)
	}
	appendTo(path, `{"event":"new1"}`+"\n")
	if got := events(f.poll()); got != "half,new1" {
		t.Fatalf("poll after rotation = %q", got)
	}
	if got := events(f.poll()); got != "" {
		t.Fatalf("a quiet poll repeated %q", got)
	}
}

func TestBridgeLogsFollowPrintsNewEntries(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bridge.log")
	writeLines(t, path, `{"time":"2026-10-30T09:00:00Z","event":"before"}`)
	cmd := newBridgeLogsCmd()
	out := &lockedBuffer{}
	cmd.SetOut(out)
	cmd.SetErr(&bytes.Buffer{})
	cmd.SetArgs([]string{"--log-file", path, "-f", "--json"})
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- cmd.ExecuteContext(ctx) }()

	deadline := time.Now().Add(5 * time.Second)
	for !strings.Contains(out.String(), "before") && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	fh, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		t.Fatal(err)
	}
	fh.WriteString(`{"time":"2026-10-30T09:01:00Z","event":"after"}` + "\n")
	fh.Close()
	for !strings.Contains(out.String(), "after") && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	if got := out.String(); strings.Count(got, "before") != 1 || strings.Count(got, "after") != 1 {
		t.Fatalf("output = %q", got)
	}
}

type lockedBuffer struct {
	mu sync.Mutex
	b  bytes.Buffer
}

func (l *lockedBuffer) Write(p []byte) (int, error) {
	l.mu.Lock()
	defer l.mu.Unlock()

	return l.b.Write(p)
}

func (l *lockedBuffer) String() string {
	l.mu.Lock()
	defer l.mu.Unlock()

	return l.b.String()
}

func TestBridgeLoggerNamesTheBridgeOnEachEntry(t *testing.T) {
	var out bytes.Buffer
	name := &bridgeLogName{}
	log := newNamedBridgeLogger(&out, name)

	log.Info("before_name")
	name.set("mac")
	log.Info("started", "card", 7)
	log.With("rule", "r").WithGroup("g").Warn("grouped", "k", 1)
	name.set("renamed")
	log.Info("after_reload")

	lines := strings.Split(strings.TrimSpace(out.String()), "\n")
	if strings.Contains(lines[0], `"bridge"`) {
		t.Errorf("an entry before the name carries one: %s", lines[0])
	}
	if !strings.Contains(lines[1], `"event":"started","bridge":"mac","card":7`) {
		t.Errorf("line 2 = %s", lines[1])
	}
	if !strings.Contains(lines[2], `"bridge":"mac"`) {
		t.Errorf("line 3 = %s", lines[2])
	}
	if !strings.Contains(lines[3], `"bridge":"renamed"`) {
		t.Errorf("line 4 = %s", lines[3])
	}
	var _ slog.Handler = namedHandler{}
}

func TestBridgeStateFileSurvivesAndNamesTheLog(t *testing.T) {
	shortConfigHome(t)
	path, err := statePath("rules.yaml")
	if err != nil {
		t.Fatal(err)
	}
	lock, _ := lockPath("rules.yaml")
	if strings.TrimSuffix(filepath.Base(lock), ".lock") != strings.TrimSuffix(filepath.Base(path), ".state.json") {
		t.Fatalf("state %s and lock %s must share a hash", path, lock)
	}
	want := bridgeState{Name: "mac", RulesPath: "/r.yaml", LogFile: "/l.log", PID: 4, Version: "v1", StartedAt: time.Unix(1, 0).UTC()}
	if err := writeBridgeState(path, want); err != nil {
		t.Fatal(err)
	}
	got, err := readBridgeStates()
	if err != nil || len(got) != 1 || got[0] != want {
		t.Fatalf("states = %v, %v", got, err)
	}
	if info, _ := os.Stat(path); info.Mode().Perm() != 0o600 {
		t.Errorf("mode = %v", info.Mode())
	}
}

func TestBackfillReadsTheDatedFilesOfARotatedLog(t *testing.T) {
	src, err := os.ReadFile(filepath.Join("testdata", "backfill", "bridge.log"))
	if err != nil {
		t.Fatal(err)
	}
	whole := filepath.Join(t.TempDir(), "bridge.log")
	if err := os.WriteFile(whole, src, 0o600); err != nil {
		t.Fatal(err)
	}
	want, err := readWorkerSessions(whole, "")
	if err != nil {
		t.Fatal(err)
	}

	lines := bytes.SplitAfter(src, []byte("\n"))
	half := len(lines) / 2
	rotated := filepath.Join(t.TempDir(), "bridge.log")
	if err := os.WriteFile(logfile.DatedPath(rotated, time.Date(2026, 10, 29, 0, 0, 0, 0, time.Local)), bytes.Join(lines[:half], nil), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(rotated, bytes.Join(lines[half:], nil), 0o600); err != nil {
		t.Fatal(err)
	}
	got, err := readWorkerSessions(rotated, "")
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != len(want) {
		t.Fatalf("a rotated log gave %d sessions, want %d", len(got), len(want))
	}
	for i := range want {
		if got[i].id != want[i].id || len(got[i].windows) != len(want[i].windows) {
			t.Errorf("session %d differs: %+v vs %+v", i, got[i], want[i])
		}
	}
}
