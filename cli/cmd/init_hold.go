package cmd

import (
	"context"
	"fmt"
	"io"
	"os"
	"os/signal"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
)

// initRenewEvery is how often init tells Loupe that it still runs. The server
// closes an init run that stays quiet for five minutes.
var initRenewEvery = 60 * time.Second

// waitEndGrace is how long after a wait an interrupt still counts for it.
const waitEndGrace = 200 * time.Millisecond

// initBridgeID names this machine to the server. A test replaces it.
var initBridgeID = config.EnsureBridgeID

// initRunClient is what the hold needs from the Loupe API.
type initRunClient interface {
	ReportInteractiveLaunch(ctx context.Context, handle, sessionID string, report api.InteractiveLaunchReport) (bool, error)
	CloseInteractiveSession(ctx context.Context, sessionID string) (int, error)
}

// initRun is the interactive run that tells Loupe that `loupe init` works on a
// project, so the inbox opens no onboarding item meanwhile. A run that could
// not start is inert: every method does nothing.
type initRun struct {
	client    initRunClient
	projectID string
	bridgeID  string
	sessionID string

	mu       sync.Mutex
	waiting  bool
	finished bool

	waitEnded time.Time

	// sendMu keeps a renewal and a mark change from interleaving their PUTs.
	sendMu sync.Mutex

	// inWait is true while the install wait owns Ctrl-C.
	inWait atomic.Bool

	stop     chan struct{}
	stopOnce sync.Once
	done     chan struct{}
	notify   chan os.Signal
	// waitSigs carries the Ctrl-C that the loop hands to a running wait.
	waitSigs chan os.Signal
}

// startInitRun opens the init run of a project and starts its renewal and its
// signal handler. When the launch fails for any reason, it prints a note and
// returns an inert run, because init worked before the hold existed.
func startInitRun(ctx context.Context, out io.Writer, client initRunClient, projectID string) *initRun {
	r := &initRun{client: client, projectID: projectID, sessionID: config.NewUUID(), stop: make(chan struct{}), done: make(chan struct{})}
	bridgeID, err := initBridgeID()
	if err == nil {
		r.bridgeID = bridgeID
		_, err = r.send(ctx, nil)
	}
	if err != nil {
		fmt.Fprintln(out, "This Loupe server cannot hold the onboarding inbox items. They may appear while init runs.")
		close(r.done)
		r.finished = true

		return r
	}

	r.notify = make(chan os.Signal, 1)
	r.waitSigs = make(chan os.Signal, 1)
	signal.Notify(r.notify, os.Interrupt, syscall.SIGTERM)
	go r.loop(initRenewEvery)

	return r
}

// held says whether the run is open on the server.
func (r *initRun) held() bool {
	return r != nil && r.client != nil && !r.isFinished()
}

func (r *initRun) isFinished() bool {
	r.mu.Lock()
	defer r.mu.Unlock()

	return r.finished
}

// send repeats the launch PUT. awaiting nil leaves the wait mark alone.
func (r *initRun) send(ctx context.Context, awaiting *bool) (bool, error) {
	return r.client.ReportInteractiveLaunch(ctx, r.projectID, r.sessionID, api.InteractiveLaunchReport{
		BridgeID:        r.bridgeID,
		SubjectType:     api.SubjectProject,
		SubjectID:       r.projectID,
		WorkKind:        "init",
		State:           api.RunRunning,
		At:              time.Now().UTC(),
		AwaitingInstall: awaiting,
	})
}

// loop renews the run and reacts to a signal until the run stops.
func (r *initRun) loop(every time.Duration) {
	defer close(r.done)
	tick := time.NewTicker(every)
	defer tick.Stop()
	for {
		select {
		case <-r.stop:
			return
		case <-tick.C:
			r.renew()
		case sig := <-r.notify:
			if sig == os.Interrupt && r.keepsInWait() {
				select {
				case r.waitSigs <- sig:
				default:
				}

				continue
			}
			r.exitOn(sig)

			return
		}
	}
}

// beginWait gives Ctrl-C to the install wait.
func (r *initRun) beginWait() {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.inWait.Store(true)
}

// endWait takes Ctrl-C back and drops an interrupt that the wait already
// owned but the loop has not read yet.
func (r *initRun) endWait() {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.inWait.Store(false)
	r.waitEnded = time.Now()
	if r.notify == nil {
		return
	}
	for {
		select {
		case sig := <-r.notify:
			if sig != os.Interrupt {
				select {
				case r.notify <- sig:
				default:
				}

				return
			}
		default:
			return
		}
	}
}

// keepsInWait says whether an interrupt belongs to the wait: the wait runs, or
// it ended just now, while the loop held the interrupt.
func (r *initRun) keepsInWait() bool {
	r.mu.Lock()
	defer r.mu.Unlock()

	return r.inWait.Load() || time.Since(r.waitEnded) < waitEndGrace
}

func (r *initRun) renew() {
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	r.sendMu.Lock()
	defer r.sendMu.Unlock()
	r.mu.Lock()
	var awaiting *bool
	if r.waiting {
		t := true
		awaiting = &t
	}
	r.mu.Unlock()
	_, _ = r.send(ctx, awaiting)
}

// exitOn closes the run and ends the process the way the signal would have.
func (r *initRun) exitOn(sig os.Signal) {
	r.closeRun()
	signal.Reset(os.Interrupt, syscall.SIGTERM)
	if p, err := os.FindProcess(os.Getpid()); err == nil && p.Signal(sig) == nil {
		time.Sleep(time.Second)
	}
	os.Exit(1)
}

// setWaiting sets or clears the wait mark. It says whether the server took it.
func (r *initRun) setWaiting(ctx context.Context, on bool) bool {
	if !r.held() {
		return false
	}
	r.sendMu.Lock()
	defer r.sendMu.Unlock()
	r.mu.Lock()
	r.waiting = on
	r.mu.Unlock()
	_, err := r.send(ctx, &on)

	return err == nil
}

// Close ends the run on the server. It is safe to call twice.
func (r *initRun) Close() {
	if r == nil {
		return
	}
	r.closeRun()
}

func (r *initRun) closeRun() {
	r.mu.Lock()
	if r.finished {
		r.mu.Unlock()

		return
	}
	r.finished = true
	r.mu.Unlock()

	r.stopOnce.Do(func() { close(r.stop) })
	if r.notify != nil {
		signal.Stop(r.notify)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_, _ = r.client.CloseInteractiveSession(ctx, r.sessionID)
}
