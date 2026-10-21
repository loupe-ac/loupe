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
	"time"
	"unicode/utf8"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/outbound"
)

// The streaming of a command's output. Tests set these.
var (
	// outputInterval is the wait between two reads of the output file.
	outputInterval = time.Second
	// outputRequestTimeout bounds one request.
	outputRequestTimeout = 5 * time.Second
	// outputFlushWait bounds the last flush after the command exits.
	outputFlushWait = 5 * time.Second
)

const (
	// outputChunk is the most bytes one request carries.
	outputChunk = 64 << 10
	// outputMaxFailures is how many requests in a row may fail before the
	// stream stops.
	outputMaxFailures = 3
)

// outputSender sends one chunk of output at offset, and returns what Loupe
// holds after it.
type outputSender func(ctx context.Context, offset int64, text string) (api.RunOutputAnswer, error)

// outputStreamer sends the output file of a running command to Loupe. It
// reads the file from its own position, so a slow or failed request never
// delays the command. Loupe appends a chunk only at the length it holds, so a
// chunk sent twice is stored once.
type outputStreamer struct {
	path  string
	send  outputSender
	log   *slog.Logger
	ready <-chan struct{}

	ctx    context.Context
	cancel context.CancelFunc
	quit   chan struct{}
	done   chan struct{}
	once   sync.Once

	// The fields below belong to the streaming goroutine. readPos is the
	// start of the unsent bytes of the file. offset is the length Loupe
	// holds. chunk is the text read at readPos and not yet taken, and used
	// is how many bytes of the file it covers.
	readPos  int64
	offset   int64
	chunk    string
	used     int64
	failures int
	stopped  bool
}

// startOutput starts the stream of the output of p's command, which proc
// runs. It sends nothing before Loupe holds the running report: the marker
// sits behind that report in the ordered queue. The caller holds mu, right
// after it queued the report. It returns nil when the run reports nothing.
func (r *router) startOutput(p pending, proc workerProc) *outputStreamer {
	subjectType, _, _ := subjectOf(p)
	if !r.reporting() || subjectType == "" || (p.isWork() && p.claimToken == "") || proc.dir == "" {
		return nil
	}
	ready := make(chan struct{})
	var once sync.Once
	release := func() { once.Do(func() { close(ready) }) }
	r.reports.Enqueue(outbound.Report{
		Rule: p.rule,
		Send: func(context.Context) (bool, error) {
			release()

			return true, nil
		},
		Lost: func(outbound.LossReason) { release() },
	})
	handle, runID, bridgeID, runs := p.event.ProjectID, p.runID, r.bridgeID, r.runs
	send := func(ctx context.Context, offset int64, text string) (api.RunOutputAnswer, error) {
		return runs.output(ctx, handle, runID, bridgeID, offset, text)
	}

	return newOutputStreamer(r.workerContext(), filepath.Join(proc.dir, commandProc.name+".stdout"), send, r.log.With(about(p.event, p.rule)...), ready)
}

func newOutputStreamer(parent context.Context, path string, send outputSender, log *slog.Logger, ready <-chan struct{}) *outputStreamer {
	ctx, cancel := context.WithCancel(parent)
	s := &outputStreamer{
		path: path, send: send, log: log, ready: ready,
		ctx: ctx, cancel: cancel, quit: make(chan struct{}), done: make(chan struct{}),
	}
	go s.run()

	return s
}

// finish flushes the rest of the output and stops the stream. It waits at most
// outputFlushWait, so a Loupe that does not answer never holds the run. The
// caller sends the final state of the run after it returns. A nil streamer does
// nothing.
func (s *outputStreamer) finish() {
	if s == nil {
		return
	}
	s.once.Do(func() { close(s.quit) })
	timer := time.NewTimer(outputFlushWait)
	defer timer.Stop()
	select {
	case <-s.done:
	case <-timer.C:
		s.cancel()
		<-s.done
	}
	s.cancel()
}

func (s *outputStreamer) run() {
	defer close(s.done)

	select {
	case <-s.ready:
	case <-s.quit:
		s.flush()

		return
	case <-s.ctx.Done():
		return
	}
	ticker := time.NewTicker(outputInterval)
	defer ticker.Stop()
	for {
		s.pump(false)
		if s.stopped {
			return
		}
		select {
		case <-ticker.C:
		case <-s.quit:
			s.flush()

			return
		case <-s.ctx.Done():
			return
		}
	}
}

// flush sends what is left once the command has exited, after the running
// report if that is still on its way.
func (s *outputStreamer) flush() {
	select {
	case <-s.ready:
	case <-s.ctx.Done():
		return
	}
	s.pump(true)
}

// pump sends the file until it is caught up. Outside the final flush, a failed
// request ends the pass and the next tick tries again.
func (s *outputStreamer) pump(final bool) {
	for !s.stopped && s.ctx.Err() == nil {
		if s.chunk == "" {
			s.chunk, s.used = s.read(final)
			if s.chunk == "" {
				return
			}
		}
		if s.sendChunk() {
			continue
		}
		if !final {
			return
		}
		select {
		case <-time.After(outputInterval):
		case <-s.ctx.Done():
			return
		}
	}
}

// read takes the next chunk of the file at readPos. Outside the final flush it
// leaves an incomplete rune at the end for the next read. Bytes that are not
// UTF-8 become U+FFFD.
func (s *outputStreamer) read(final bool) (string, int64) {
	f, err := os.Open(s.path)
	if err != nil {
		return "", 0
	}
	defer f.Close()

	buf := make([]byte, outputChunk)
	n, err := f.ReadAt(buf, s.readPos)
	if n == 0 || (err != nil && !errors.Is(err, io.EOF)) {
		return "", 0
	}
	buf = buf[:n]
	if !final {
		buf = buf[:completeRunes(buf)]
	}

	return strings.ToValidUTF8(string(buf), "�"), int64(len(buf))
}

// completeRunes is the length of b without an incomplete rune at its end.
func completeRunes(b []byte) int {
	for i := len(b) - 1; i >= 0 && i >= len(b)-utf8.UTFMax; i-- {
		if !utf8.RuneStart(b[i]) {
			continue
		}
		if !utf8.FullRune(b[i:]) {
			return i
		}

		break
	}

	return len(b)
}

// sendChunk sends the current chunk, and reports whether Loupe took it. Loupe
// has taken a chunk when the length it answers is the end of that chunk. A
// different length with no cap means the two sides disagree, and the stream
// stops: Loupe does not append at another offset.
func (s *outputStreamer) sendChunk() bool {
	ctx, cancel := context.WithTimeout(s.ctx, outputRequestTimeout)
	answer, err := s.send(ctx, s.offset, s.chunk)
	cancel()
	switch {
	case err != nil:
		if s.ctx.Err() != nil {
			return false
		}
		s.failures++
		if s.failures >= outputMaxFailures {
			s.stop("the requests failed", "error", err.Error())
		}

		return false
	case answer.Capped:
		s.stopped = true

		return false
	case answer.Length != s.offset+int64(len(s.chunk)):
		s.stop("Loupe holds a different length of the output")

		return false
	}
	s.offset = answer.Length
	s.readPos += s.used
	s.chunk, s.used, s.failures = "", 0, 0

	return true
}

func (s *outputStreamer) stop(why string, args ...any) {
	s.stopped = true
	s.log.Warn("output_stream_stopped", append([]any{"message", why}, args...)...)
}
