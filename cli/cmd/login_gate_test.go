package cmd

import (
	"bytes"
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/oauth"
)

// flakySource fails with ErrLoginExpired until fixed is set.
type flakySource struct {
	fixed atomic.Bool
	calls atomic.Int32
}

func (s *flakySource) Token(context.Context) (string, error) {
	s.calls.Add(1)
	if !s.fixed.Load() {
		return "", oauth.ErrLoginExpired
	}

	return "new", nil
}

func (s *flakySource) Refresh(ctx context.Context, _ string) (string, error) { return s.Token(ctx) }

func TestLoginGateWaitsQuietlyThenResumes(t *testing.T) {
	dir := t.TempDir()
	cfgPath := filepath.Join(dir, "config.json")
	if err := os.WriteFile(cfgPath, []byte("{}"), 0o600); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	src := &flakySource{}
	ticks := make(chan time.Time)
	var arms atomic.Int32
	var notices atomic.Int32
	g := newLoginGate(ctx, newBridgeLogger(&out), cfgPath, func(ctx context.Context) error {
		_, err := src.Token(ctx)

		return err
	})
	g.notice = func(context.Context) { notices.Add(1) }
	g.after = func(time.Duration) <-chan time.Time {
		arms.Add(1)

		return ticks
	}
	gated := gatedSource{inner: src, gate: g}

	type result struct {
		token string
		err   error
	}
	done := make(chan result, 3)
	for range 3 {
		go func() {
			tok, err := gated.Token(ctx)
			done <- result{tok, err}
		}()
	}

	select {
	case r := <-done:
		t.Fatalf("a call returned while the login was expired: %+v", r)
	case <-time.After(100 * time.Millisecond):
	}

	// The first tick probes, and the expired login keeps the gate closed.
	callsBefore := src.calls.Load()
	ticks <- time.Now()
	for arms.Load() < 2 {
		time.Sleep(time.Millisecond)
	}
	if src.calls.Load() != callsBefore+1 {
		t.Fatal("the first tick did not probe")
	}

	// An unchanged file earns no probe.
	callsBefore = src.calls.Load()
	ticks <- time.Now()
	for arms.Load() < 3 {
		time.Sleep(time.Millisecond)
	}
	if src.calls.Load() != callsBefore {
		t.Fatal("the gate probed an unchanged config file")
	}

	// A new login: the file changes and the source accepts it.
	src.fixed.Store(true)
	if err := os.WriteFile(cfgPath, []byte(`{"login":"new"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	ticks <- time.Now()

	for range 3 {
		select {
		case r := <-done:
			if r.err != nil || r.token != "new" {
				t.Fatalf("result = %+v, want the new token", r)
			}
		case <-time.After(2 * time.Second):
			t.Fatal("a waiting call did not resume")
		}
	}

	log := out.String()
	if n := strings.Count(log, `"event":"login_expired"`); n != 1 {
		t.Fatalf("login_expired logged %d times, want 1:\n%s", n, log)
	}
	if !strings.Contains(log, `"event":"login_resumed"`) {
		t.Fatalf("no login_resumed line:\n%s", log)
	}
	if notices.Load() != 1 {
		t.Fatalf("notices = %d, want 1", notices.Load())
	}
}

func TestLoginGateFindsLoginWrittenBeforeTheWatcherStarts(t *testing.T) {
	cfgPath := filepath.Join(t.TempDir(), "config.json")
	if err := os.WriteFile(cfgPath, []byte(`{"login":"new"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	src := &flakySource{}
	src.fixed.Store(true)
	ticks := make(chan time.Time, 1)
	g := newLoginGate(ctx, newBridgeLogger(&bytes.Buffer{}), cfgPath, func(ctx context.Context) error {
		_, err := src.Token(ctx)

		return err
	})
	g.notice = func(context.Context) {}
	g.after = func(time.Duration) <-chan time.Time { return ticks }
	g.trip()
	ticks <- time.Now()

	waitCtx, stop := context.WithTimeout(ctx, 2*time.Second)
	defer stop()
	if err := g.wait(waitCtx); err != nil {
		t.Fatalf("the gate stayed closed after a login that landed before the watcher: %v", err)
	}
}

func TestGatedSourcePassesOtherErrors(t *testing.T) {
	ctx := context.Background()
	g := newLoginGate(ctx, newBridgeLogger(&bytes.Buffer{}), filepath.Join(t.TempDir(), "config.json"), nil)
	boom := errors.New("boom")
	s := gatedSource{inner: errSource{boom}, gate: g}

	if _, err := s.Token(ctx); !errors.Is(err, boom) {
		t.Fatalf("Token error = %v, want boom", err)
	}
	if g.tripped {
		t.Fatal("an ordinary error tripped the gate")
	}
}

func TestLoginGateWaitEndsWithContext(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	g := newLoginGate(context.Background(), newBridgeLogger(&bytes.Buffer{}), filepath.Join(t.TempDir(), "config.json"), nil)
	g.notice = func(context.Context) {}
	g.after = func(time.Duration) <-chan time.Time { return nil }
	g.trip()
	cancel()

	if err := g.wait(ctx); !errors.Is(err, context.Canceled) {
		t.Fatalf("wait error = %v, want context.Canceled", err)
	}
}

type errSource struct{ err error }

func (s errSource) Token(context.Context) (string, error)           { return "", s.err }
func (s errSource) Refresh(context.Context, string) (string, error) { return "", s.err }
