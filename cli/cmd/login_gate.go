package cmd

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/notify"
	"github.com/loupe-ac/loupe/cli/internal/oauth"
)

// loginPollInterval is how often a tripped gate looks at config.json.
const loginPollInterval = 5 * time.Second

// loginNoticeRepeat is how often a closed gate shows the notice again.
const loginNoticeRepeat = time.Hour

const (
	loginExpiredMessage = "Your Loupe login expired. Run `loupe login` in another terminal. The bridge resumes by itself."
	loginResumedMessage = "Login found. Bridge resumed."
	loginNoticeTitle    = "Loupe bridge needs a new login"
	loginNoticeText     = "Run loupe login in a terminal. The bridge resumes by itself."
)

// loginGate puts the whole bridge in one waiting state while its login is
// expired. The first expired-login error trips it. Every token request then
// waits, so no call fails, no retry is spent and no new claim starts. A running
// worker keeps running. The gate opens when config.json holds a login that the
// token source accepts.
type loginGate struct {
	log *slog.Logger
	// configPath is the file to watch. probe asks for a token with the login on
	// disk. notice shows the macOS notice. after is time.After, and a field so
	// a test waits for no real interval.
	configPath string
	probe      func(context.Context) error
	notice     func(context.Context)
	after      func(time.Duration) <-chan time.Time

	mu      sync.Mutex
	ctx     context.Context
	tripped bool
	// open is closed when the gate opens, and replaced at each trip.
	open chan struct{}
	// openings counts the times the gate opened.
	openings uint64
}

func newLoginGate(ctx context.Context, log *slog.Logger, configPath string, probe func(context.Context) error) *loginGate {
	return &loginGate{
		log:        log,
		configPath: configPath,
		probe:      probe,
		notice: func(ctx context.Context) {
			_ = notify.Send(ctx, loginNoticeTitle, loginNoticeText)
		},
		after: time.After,
		ctx:   ctx,
	}
}

// trip closes the gate. Only the first call since the last opening logs, shows
// the notice and starts the watcher. seen is the openings count read before the
// failed call. A call that started before the last opening used the old login,
// so its error does not close the gate again.
func (g *loginGate) trip(seen uint64) {
	g.mu.Lock()
	if g.tripped || g.openings != seen {
		g.mu.Unlock()

		return
	}
	g.tripped = true
	g.open = make(chan struct{})
	open := g.open
	g.mu.Unlock()

	g.log.Warn("login_expired", "message", loginExpiredMessage)
	go g.notice(g.ctx)
	go g.watch(open)
}

// expired says whether the gate is closed.
func (g *loginGate) expired() bool {
	g.mu.Lock()
	defer g.mu.Unlock()

	return g.tripped
}

// wait blocks while the gate is closed, and returns the error of ctx when it
// ends first.
func (g *loginGate) wait(ctx context.Context) error {
	for {
		g.mu.Lock()
		open := g.open
		tripped := g.tripped
		g.mu.Unlock()
		if !tripped {
			return nil
		}
		select {
		case <-open:
		case <-ctx.Done():
			return ctx.Err()
		case <-g.ctx.Done():
			return g.ctx.Err()
		}
	}
}

// watch looks at config.json every loginPollInterval. A changed file earns a
// probe, and a probe that gives a token opens the gate. The first tick always
// probes, because a login can land before the watcher reads the file. Only an
// expired login marks the file as seen, so a network error probes again.
// While the gate stays closed, the notice shows again every loginNoticeRepeat.
func (g *loginGate) watch(open chan struct{}) {
	var seen os.FileInfo
	poll, repeat := g.after(loginPollInterval), g.after(loginNoticeRepeat)
	for {
		select {
		case <-g.ctx.Done():
			return
		case <-repeat:
			repeat = g.after(loginNoticeRepeat)
			go g.notice(g.ctx)

			continue
		case <-poll:
			poll = g.after(loginPollInterval)
		}
		now, err := os.Stat(g.configPath)
		if err != nil || unchanged(seen, now) {
			continue
		}
		if err := g.probe(g.ctx); err != nil {
			if errors.Is(err, oauth.ErrLoginExpired) {
				seen = now
			}

			continue
		}
		g.mu.Lock()
		g.tripped = false
		g.openings++
		g.mu.Unlock()
		g.log.Info("login_resumed", "message", loginResumedMessage)
		close(open)

		return
	}
}

// gatedSource wraps a token source of the bridge. An expired login trips the
// gate and the call waits for it, so the caller never sees ErrLoginExpired.
type gatedSource struct {
	inner api.TokenSource
	gate  *loginGate
}

func (s gatedSource) Token(ctx context.Context) (string, error) {
	return s.gated(ctx, func() (string, error) { return s.inner.Token(ctx) })
}

func (s gatedSource) Refresh(ctx context.Context, rejected string) (string, error) {
	return s.gated(ctx, func() (string, error) { return s.inner.Refresh(ctx, rejected) })
}

func (s gatedSource) gated(ctx context.Context, call func() (string, error)) (string, error) {
	for {
		if err := s.gate.wait(ctx); err != nil {
			return "", err
		}
		s.gate.mu.Lock()
		seen := s.gate.openings
		s.gate.mu.Unlock()
		token, err := call()
		if !errors.Is(err, oauth.ErrLoginExpired) {
			return token, err
		}
		s.gate.trip(seen)
	}
}

// bridgeGate is the gate of the running bridge. It is nil in every other
// command, whose callers read the expired-login error and tell the person.
var (
	bridgeGateMu sync.RWMutex
	bridgeGate   *loginGate
)

func setBridgeGate(g *loginGate) {
	bridgeGateMu.Lock()
	bridgeGate = g
	bridgeGateMu.Unlock()
}

func currentBridgeGate() *loginGate {
	bridgeGateMu.RLock()
	defer bridgeGateMu.RUnlock()

	return bridgeGate
}

// startLoginGate makes the gate of a bridge. A bridge with no config path runs
// without one.
func startLoginGate(ctx context.Context, log *slog.Logger, cfg config.Config) {
	path, err := config.Path()
	if err != nil || cfg.OAuth == nil {
		return
	}
	hc := &http.Client{Timeout: refreshTimeout, Transport: withUserAgent(nil)}
	setBridgeGate(newLoginGate(ctx, log, path, func(ctx context.Context) error {
		return probeLogin(ctx, cfg.BaseURL, hc)
	}))
}

// probeLogin spends the refresh token on disk, so only a login that Loupe
// accepts opens the gate. A cached access token proves nothing, because a
// revoked login can keep one with time left.
func probeLogin(ctx context.Context, baseURL string, hc *http.Client) error {
	cfg, err := config.Load()
	if errors.Is(err, config.ErrNotLoggedIn) {
		return oauth.ErrLoginExpired
	}
	if err != nil {
		return err
	}
	_, err = oauth.NewSource(baseURL, *cfg.OAuth, hc).Refresh(ctx, cfg.OAuth.AccessToken)

	return err
}
