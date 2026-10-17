package cmd

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// outputServer is Loupe's side of the output endpoint. It appends a chunk only
// at the length it holds, and records every request.
type outputServer struct {
	mu       sync.Mutex
	held     string
	requests []outputSent
	cap      int
	// fail decides the answer to request n, counted from 1. An error with
	// store set comes after Loupe stored the chunk.
	fail func(n int) (err error, store bool)
	// shift is added to every length Loupe answers.
	shift int64
	// beforeAns runs when a request arrives.
	beforeAns func()
}

func (o *outputServer) send(_ context.Context, offset int64, text string) (api.RunOutputAnswer, error) {
	o.mu.Lock()
	defer o.mu.Unlock()
	o.requests = append(o.requests, outputSent{offset: offset, text: text})
	if o.beforeAns != nil {
		o.beforeAns()
	}
	if o.fail != nil {
		if err, store := o.fail(len(o.requests)); err != nil {
			if store && offset == int64(len(o.held)) {
				o.held += text
			}

			return api.RunOutputAnswer{}, err
		}
	}
	if offset == int64(len(o.held)) {
		o.held += text
	}
	answer := api.RunOutputAnswer{Length: int64(len(o.held)) + o.shift}
	if o.cap > 0 && len(o.held) >= o.cap {
		answer.Capped = true
	}

	return answer, nil
}

func (o *outputServer) stored() string {
	o.mu.Lock()
	defer o.mu.Unlock()

	return o.held
}

func (o *outputServer) count() int {
	o.mu.Lock()
	defer o.mu.Unlock()

	return len(o.requests)
}

func fastOutput(t *testing.T) {
	t.Helper()
	was := [3]time.Duration{outputInterval, outputRequestTimeout, outputFlushWait}
	outputInterval, outputRequestTimeout, outputFlushWait = 5*time.Millisecond, time.Second, 3*time.Second
	t.Cleanup(func() { outputInterval, outputRequestTimeout, outputFlushWait = was[0], was[1], was[2] })
}

// streamOf starts a streamer on a new output file. A nil ready is open at once.
func streamOf(t *testing.T, srv *outputServer, ready <-chan struct{}) (*outputStreamer, string, *syncBuffer) {
	t.Helper()
	fastOutput(t)
	if ready == nil {
		open := make(chan struct{})
		close(open)
		ready = open
	}
	path := filepath.Join(t.TempDir(), "command.stdout")
	if err := os.WriteFile(path, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	log := &syncBuffer{}
	s := newOutputStreamer(context.Background(), path, srv.send, slog.New(slog.NewJSONHandler(log, nil)), ready)
	t.Cleanup(s.finish)

	return s, path, log
}

func appendTo(t *testing.T, path string, data []byte) {
	t.Helper()
	f, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	if _, err := f.Write(data); err != nil {
		t.Fatal(err)
	}
}

func waitUntil(t *testing.T, what string, ok func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !ok() {
		if time.Now().After(deadline) {
			t.Fatalf("never saw %s", what)
		}
		time.Sleep(time.Millisecond)
	}
}

// A long output goes out in chunks of at most 64 KiB, each at the length Loupe
// holds.
func TestTheStreamCutsTheOutputIntoChunks(t *testing.T) {
	srv := &outputServer{}
	s, path, _ := streamOf(t, srv, nil)
	body := strings.Repeat("0123456789abcdef\n", 10000)
	appendTo(t, path, []byte(body))

	s.finish()

	if srv.stored() != body {
		t.Fatalf("stored %d bytes, want %d", len(srv.stored()), len(body))
	}
	var next int64
	for i, r := range srv.requests {
		if len(r.text) > outputChunk || r.offset != next {
			t.Fatalf("request %d: offset %d, %d bytes, want offset %d", i, r.offset, len(r.text), next)
		}
		next += int64(len(r.text))
	}
	if len(srv.requests) < 3 {
		t.Fatalf("%d requests for %d bytes", len(srv.requests), len(body))
	}
}

// A rune cut by the end of a read waits for its other bytes.
func TestTheStreamHoldsBackAnIncompleteRune(t *testing.T) {
	srv := &outputServer{}
	s, path, _ := streamOf(t, srv, nil)

	appendTo(t, path, []byte("ab\xc3"))
	waitUntil(t, "the complete part", func() bool { return srv.stored() == "ab" })
	time.Sleep(30 * time.Millisecond)
	if srv.stored() != "ab" {
		t.Fatalf("stored %q while a rune was open", srv.stored())
	}
	appendTo(t, path, []byte("\xa9!"))
	s.finish()

	if srv.stored() != "abé!" {
		t.Fatalf("stored %q", srv.stored())
	}
}

// The last flush sends a rune that never completes as U+FFFD, and bytes that are
// not UTF-8 too.
func TestTheFinalFlushReplacesInvalidBytes(t *testing.T) {
	srv := &outputServer{}
	s, path, _ := streamOf(t, srv, nil)

	appendTo(t, path, []byte("a\xffb\xe2\x82"))
	s.finish()

	if want := "a�b�"; srv.stored() != want {
		t.Fatalf("stored %q, want %q", srv.stored(), want)
	}
}

// An answer lost after Loupe stored the chunk makes the streamer send the same
// chunk at the same offset, and Loupe's length shows it is already there.
func TestTheStreamResendsAfterALostAnswer(t *testing.T) {
	srv := &outputServer{fail: func(n int) (error, bool) {
		if n == 1 {
			return errors.New("timeout"), true
		}

		return nil, false
	}}
	s, path, _ := streamOf(t, srv, nil)

	appendTo(t, path, []byte("hello\n"))
	waitUntil(t, "the resend", func() bool { return srv.count() >= 2 })
	appendTo(t, path, []byte("world\n"))
	s.finish()

	if srv.stored() != "hello\nworld\n" {
		t.Fatalf("stored %q", srv.stored())
	}
	if r := srv.requests; r[0].offset != 0 || r[1].offset != 0 || r[0].text != r[1].text || r[2].offset != 6 {
		t.Fatalf("requests = %+v", r)
	}
}

func TestTheStreamStopsAtTheCap(t *testing.T) {
	srv := &outputServer{cap: 4}
	s, path, _ := streamOf(t, srv, nil)

	appendTo(t, path, []byte("12345"))
	waitUntil(t, "the first request", func() bool { return srv.count() == 1 })
	appendTo(t, path, []byte("more"))
	time.Sleep(30 * time.Millisecond)
	s.finish()

	if srv.count() != 1 {
		t.Fatalf("%d requests after the cap", srv.count())
	}
}

// A length that is not the end of the chunk, with no cap, ends the stream with a
// warning, and the run goes on.
func TestTheStreamStopsWhenTheLengthDiffers(t *testing.T) {
	srv := &outputServer{shift: 3}
	s, path, log := streamOf(t, srv, nil)

	appendTo(t, path, []byte("abc"))
	waitUntil(t, "the first request", func() bool { return srv.count() == 1 })
	time.Sleep(30 * time.Millisecond)
	appendTo(t, path, []byte("def"))
	s.finish()

	if srv.count() != 1 {
		t.Fatalf("%d requests after a different length", srv.count())
	}
	if !strings.Contains(log.String(), "output_stream_stopped") {
		t.Fatalf("log = %s", log.String())
	}
}

// Three failed requests in a row stop the stream.
func TestTheStreamStopsAfterThreeFailures(t *testing.T) {
	srv := &outputServer{fail: func(int) (error, bool) { return errors.New("HTTP 502"), false }}
	s, path, log := streamOf(t, srv, nil)

	appendTo(t, path, []byte("abc"))
	waitUntil(t, "the warning", func() bool { return strings.Contains(log.String(), "output_stream_stopped") })
	appendTo(t, path, []byte("def"))
	s.finish()

	if srv.count() != 3 {
		t.Fatalf("%d requests, want 3", srv.count())
	}
}

// A success between failures resets the count.
func TestASuccessResetsTheFailureCount(t *testing.T) {
	srv := &outputServer{fail: func(n int) (error, bool) {
		if n == 1 || n == 2 || n == 4 || n == 5 {
			return errors.New("HTTP 502"), false
		}

		return nil, false
	}}
	s, path, _ := streamOf(t, srv, nil)

	appendTo(t, path, []byte("abc"))
	waitUntil(t, "the first chunk", func() bool { return srv.stored() == "abc" })
	appendTo(t, path, []byte("def"))
	s.finish()

	if srv.stored() != "abcdef" {
		t.Fatalf("stored %q", srv.stored())
	}
}

// Nothing goes out before the running report is delivered. finish waits for it,
// then sends the rest.
func TestTheStreamWaitsForTheRunningReport(t *testing.T) {
	ready := make(chan struct{})
	srv := &outputServer{beforeAns: func() {
		select {
		case <-ready:
		default:
			t.Error("a chunk went out before the running report")
		}
	}}
	s, path, _ := streamOf(t, srv, ready)

	appendTo(t, path, []byte("early\n"))
	time.Sleep(30 * time.Millisecond)
	go func() {
		time.Sleep(30 * time.Millisecond)
		close(ready)
	}()
	s.finish()

	if srv.stored() != "early\n" {
		t.Fatalf("stored %q", srv.stored())
	}
}

// A report that never lands cannot hold the run longer than the flush wait.
func TestFinishIsBounded(t *testing.T) {
	srv := &outputServer{}
	s, path, _ := streamOf(t, srv, make(chan struct{}))
	appendTo(t, path, []byte("x"))
	outputFlushWait = 50 * time.Millisecond

	began := time.Now()
	s.finish()

	if time.Since(began) > 2*time.Second || srv.count() != 0 {
		t.Fatalf("finish took %s and sent %d requests", time.Since(began), srv.count())
	}
}

// A request that hangs is cut by the timeout and the flush wait.
func TestFinishDoesNotWaitForAHangingRequest(t *testing.T) {
	hang := func(ctx context.Context, _ int64, _ string) (api.RunOutputAnswer, error) {
		<-ctx.Done()

		return api.RunOutputAnswer{}, ctx.Err()
	}
	fastOutput(t)
	outputRequestTimeout, outputFlushWait = time.Minute, 100*time.Millisecond
	path := filepath.Join(t.TempDir(), "command.stdout")
	if err := os.WriteFile(path, []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}
	open := make(chan struct{})
	close(open)
	s := newOutputStreamer(context.Background(), path, hang, slog.New(slog.NewJSONHandler(io.Discard, nil)), open)

	began := time.Now()
	s.finish()

	if time.Since(began) > 3*time.Second {
		t.Fatalf("finish took %s", time.Since(began))
	}
}

func TestCompleteRunes(t *testing.T) {
	for in, want := range map[string]int{
		"":              0,
		"abc":           3,
		"ab\xc3":        2,
		"ab\xc3\xa9":    4,
		"\xe2\x82":      0,
		"a\xe2\x82\xac": 4,
		"a\xff":         2,
		"\xf0\x9f\x98":  0,
	} {
		if got := completeRunes([]byte(in)); got != want {
			t.Errorf("completeRunes(%q) = %d, want %d", in, got, want)
		}
	}
}
