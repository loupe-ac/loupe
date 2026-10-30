package cmd

import (
	"bytes"
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/oauth"
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

// pollTicks gives the poll timer the ticks, and the notice timer none.
func pollTicks(ticks <-chan time.Time) func(time.Duration) <-chan time.Time {
	return func(d time.Duration) <-chan time.Time {
		if d == loginNoticeRepeat {
			return nil
		}

		return ticks
	}
}

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
	g.after = func(d time.Duration) <-chan time.Time {
		if d != loginPollInterval {
			return nil
		}
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

// lateSource holds its first call until release closes, then fails it with
// the old login. Every other call follows fixed.
type lateSource struct {
	flakySource
	first   atomic.Bool
	started chan struct{}
	release chan struct{}
}

func (s *lateSource) Token(ctx context.Context) (string, error) {
	if s.first.CompareAndSwap(false, true) {
		close(s.started)
		<-s.release

		return "", oauth.ErrLoginExpired
	}

	return s.flakySource.Token(ctx)
}

func (s *lateSource) Refresh(ctx context.Context, _ string) (string, error) { return s.Token(ctx) }

func TestLoginGateIgnoresALateErrorFromThePriorLogin(t *testing.T) {
	cfgPath := filepath.Join(t.TempDir(), "config.json")
	if err := os.WriteFile(cfgPath, []byte(`{"login":"new"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	src := &lateSource{started: make(chan struct{}), release: make(chan struct{})}
	ticks := make(chan time.Time)
	var notices atomic.Int32
	g := newLoginGate(ctx, newBridgeLogger(&out), cfgPath, func(context.Context) error {
		if !src.fixed.Load() {
			return oauth.ErrLoginExpired
		}

		return nil
	})
	g.notice = func(context.Context) { notices.Add(1) }
	g.after = pollTicks(ticks)
	gated := gatedSource{inner: src, gate: g}

	late := make(chan error, 1)
	go func() {
		_, err := gated.Token(ctx)
		late <- err
	}()
	<-src.started

	tripping := make(chan error, 1)
	go func() {
		_, err := gated.Token(ctx)
		tripping <- err
	}()
	for {
		g.mu.Lock()
		tripped := g.tripped
		g.mu.Unlock()
		if tripped {
			break
		}
		time.Sleep(time.Millisecond)
	}

	src.fixed.Store(true)
	ticks <- time.Now()
	if err := <-tripping; err != nil {
		t.Fatalf("the tripping call failed: %v", err)
	}

	close(src.release)
	select {
	case err := <-late:
		if err != nil {
			t.Fatalf("the late call failed: %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("the late error from the prior login closed the gate again")
	}

	if n := strings.Count(out.String(), `"event":"login_expired"`); n != 1 {
		t.Fatalf("login_expired logged %d times, want 1:\n%s", n, out.String())
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
	g.after = pollTicks(ticks)
	g.trip(0)
	ticks <- time.Now()

	waitCtx, stop := context.WithTimeout(ctx, 2*time.Second)
	defer stop()
	if err := g.wait(waitCtx); err != nil {
		t.Fatalf("the gate stayed closed after a login that landed before the watcher: %v", err)
	}
}

func TestLoginGateProbesAgainAfterANetworkError(t *testing.T) {
	cfgPath := filepath.Join(t.TempDir(), "config.json")
	if err := os.WriteFile(cfgPath, []byte(`{"login":"new"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	var probes atomic.Int32
	ticks := make(chan time.Time)
	g := newLoginGate(ctx, newBridgeLogger(&bytes.Buffer{}), cfgPath, func(context.Context) error {
		if probes.Add(1) == 1 {
			return errors.New("connection refused")
		}

		return nil
	})
	g.notice = func(context.Context) {}
	g.after = pollTicks(ticks)
	g.trip(0)
	ticks <- time.Now()
	ticks <- time.Now()

	waitCtx, stop := context.WithTimeout(ctx, 2*time.Second)
	defer stop()
	if err := g.wait(waitCtx); err != nil {
		t.Fatalf("the gate stayed closed after a network error on an unchanged file: %v", err)
	}
	if probes.Load() != 2 {
		t.Fatalf("probes = %d, want 2", probes.Load())
	}
}

func TestProbeLoginRefusesARevokedLoginWithTimeLeft(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", dir)
	t.Setenv("HOME", dir)
	var refreshes atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		refreshes.Add(1)
		w.WriteHeader(http.StatusBadRequest)
		_, _ = w.Write([]byte(`{"error":"invalid_grant"}`))
	}))
	defer server.Close()
	if err := config.Save(config.Config{BaseURL: server.URL, OAuth: &config.OAuthTokens{
		AccessToken: "revoked", RefreshToken: "dead", ExpiresAt: time.Now().Add(time.Hour),
	}}); err != nil {
		t.Fatal(err)
	}

	if err := probeLogin(context.Background(), server.URL, server.Client()); !errors.Is(err, oauth.ErrLoginExpired) {
		t.Fatalf("probe error = %v, want ErrLoginExpired", err)
	}
	if refreshes.Load() != 1 {
		t.Fatalf("refreshes = %d, want 1: the probe trusted the cached access token", refreshes.Load())
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
	g.trip(0)
	cancel()

	if err := g.wait(ctx); !errors.Is(err, context.Canceled) {
		t.Fatalf("wait error = %v, want context.Canceled", err)
	}
}

type errSource struct{ err error }

func (s errSource) Token(context.Context) (string, error)           { return "", s.err }
func (s errSource) Refresh(context.Context, string) (string, error) { return "", s.err }

func TestLoginGateShowsTheNoticeAgainEveryHourWhileClosed(t *testing.T) {
	dir := t.TempDir()
	cfgPath := filepath.Join(dir, "config.json")
	if err := os.WriteFile(cfgPath, []byte("{}"), 0o600); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	src := &flakySource{}
	polls := make(chan time.Time)
	repeats := make(chan time.Time)
	notices := make(chan struct{}, 10)
	g := newLoginGate(ctx, newBridgeLogger(&bytes.Buffer{}), cfgPath, func(ctx context.Context) error {
		_, err := src.Token(ctx)

		return err
	})
	g.notice = func(context.Context) { notices <- struct{}{} }
	g.after = func(d time.Duration) <-chan time.Time {
		if d == loginNoticeRepeat {
			return repeats
		}

		return polls
	}
	waitNotice := func(what string) {
		t.Helper()
		select {
		case <-notices:
		case <-time.After(2 * time.Second):
			t.Fatalf("no notice %s", what)
		}
	}

	g.trip(0)
	waitNotice("at trip time")
	for i := 1; i <= 2; i++ {
		repeats <- time.Now()
		waitNotice("after an hour")
	}

	// The gate opens, and the watcher stops.
	src.fixed.Store(true)
	if err := os.WriteFile(cfgPath, []byte(`{"login":"new"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	polls <- time.Now()
	if err := g.wait(ctx); err != nil {
		t.Fatal(err)
	}
	select {
	case repeats <- time.Now():
		t.Fatal("the watcher still waits for the repeat after the gate opened")
	case <-time.After(100 * time.Millisecond):
	}
	if len(notices) != 0 {
		t.Fatal("a notice came after the gate opened")
	}
}
