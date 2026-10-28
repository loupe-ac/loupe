package cmd

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"
)

const psSample = `  101 /sbin/launchd
  202 claude --session-id 0199a0e2-aaaa --model opus -- prompt
  303 node /x/claude --resume 0199a0e2-bbbb
  404 vim notes --session-id 0199a0e2-ccccdddd
`

func fakeLiveness(ps string, psErr error, transcripts map[string]time.Time) sessionLiveness {
	return sessionLiveness{
		processes: func(context.Context) (string, error) { return ps, psErr },
		transcriptAt: func(id string) (time.Time, bool) {
			at, ok := transcripts[id]

			return at, ok
		},
	}
}

func TestLivenessFindsASessionInTheProcessList(t *testing.T) {
	at := time.Date(2026, 10, 28, 9, 0, 0, 0, time.UTC)
	got := fakeLiveness(psSample, nil, map[string]time.Time{"0199a0e2-aaaa": at}).readings(context.Background(),
		[]string{"0199a0e2-aaaa", "0199a0e2-bbbb", "0199a0e2-cccc", "0199a0e2-zzzz"})

	want := []struct {
		alive bool
		at    *time.Time
	}{{true, &at}, {true, nil}, {false, nil}, {false, nil}}
	if len(got) != len(want) {
		t.Fatalf("readings = %+v", got)
	}
	for i, w := range want {
		if got[i].Alive == nil || *got[i].Alive != w.alive {
			t.Errorf("reading %d alive = %v, want %v", i, got[i].Alive, w.alive)
		}
		if (got[i].TranscriptAt == nil) != (w.at == nil) || (w.at != nil && !got[i].TranscriptAt.Equal(*w.at)) {
			t.Errorf("reading %d transcriptAt = %v, want %v", i, got[i].TranscriptAt, w.at)
		}
	}
}

func TestLivenessIsUnknownWhenPsFails(t *testing.T) {
	got := fakeLiveness("", errors.New("no ps"), nil).readings(context.Background(), []string{"s1"})
	if len(got) != 1 || got[0].SessionID != "s1" || got[0].Alive != nil {
		t.Fatalf("readings = %+v", got)
	}
}

func TestLivenessIsUnknownWhenPsListsNothing(t *testing.T) {
	got := fakeLiveness("  \n", nil, nil).readings(context.Background(), []string{"s1"})
	if len(got) != 1 || got[0].Alive != nil {
		t.Fatalf("readings = %+v", got)
	}
}

func TestLivenessReadsNothingForAnEmptyWatchSet(t *testing.T) {
	called := false
	l := sessionLiveness{processes: func(context.Context) (string, error) { called = true; return psSample, nil }}
	if got := l.readings(context.Background(), nil); got != nil || called {
		t.Fatalf("readings = %+v, ps called = %v", got, called)
	}
}

func TestSessionNamedInTheCommandLineMatchesWholeIds(t *testing.T) {
	for _, tc := range []struct {
		line, id string
		want     bool
	}{
		{"claude --session-id abc --model x", "abc", true},
		{"claude --session-id abc", "abc", true},
		{"claude --session-id abcd", "abc", false},
		{"claude --resume abc", "abc", true},
		{"claude --session-id=abc", "abc", true},
		{"claude --other abc", "abc", false},
		{"claude --session-id xabc", "abc", false},
	} {
		if got := namesSession(tc.line, tc.id); got != tc.want {
			t.Errorf("namesSession(%q, %q) = %v", tc.line, tc.id, got)
		}
	}
}

func TestNewestTranscriptTakesTheLatestAcrossFolders(t *testing.T) {
	old, recent := t.TempDir(), t.TempDir()
	older := time.Date(2026, 10, 1, 8, 0, 0, 0, time.UTC)
	newer := time.Date(2026, 10, 2, 8, 0, 0, 0, time.UTC)
	for dir, at := range map[string]time.Time{old: older, recent: newer} {
		path := filepath.Join(dir, "projects", "p", "sess.jsonl")
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte("{}\n"), 0o600); err != nil {
			t.Fatal(err)
		}
		if err := os.Chtimes(path, at, at); err != nil {
			t.Fatal(err)
		}
	}

	got, ok := newestTranscript([]string{old, recent, t.TempDir()}, "sess")
	if !ok || !got.Equal(newer) {
		t.Fatalf("got %v, %v", got, ok)
	}
	if _, ok := newestTranscript([]string{old}, "missing"); ok {
		t.Fatal("found a transcript that does not exist")
	}
}

func TestLivenessReportsACodexSessionByItsSessionFileAlone(t *testing.T) {
	at := time.Date(2026, 10, 28, 9, 0, 0, 0, time.UTC)
	l := fakeLiveness(psSample, nil, nil)
	l.codex = func(id string) (bool, time.Time, bool) {
		switch id {
		case "codex-written":
			return true, at, true
		case "codex-new":
			return true, time.Time{}, false
		}

		return false, time.Time{}, false
	}

	got := l.readings(context.Background(), []string{"codex-written", "codex-new", "0199a0e2-aaaa"})

	if len(got) != 3 {
		t.Fatalf("readings = %+v", got)
	}
	if got[0].Alive != nil || got[0].TranscriptAt == nil || !got[0].TranscriptAt.Equal(at) {
		t.Errorf("codex reading = %+v, want no process answer and the session file time", got[0])
	}
	if got[1].Alive != nil || got[1].TranscriptAt != nil {
		t.Errorf("new codex reading = %+v, want neither", got[1])
	}
	if got[2].Alive == nil || !*got[2].Alive {
		t.Errorf("claude reading = %+v, want alive", got[2])
	}
}
