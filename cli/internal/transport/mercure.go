// Package transport subscribes to a Mercure hub over Server-Sent Events and
// delivers each event's data payload to a handler. The connection is outbound,
// so it works from behind NAT with no inbound path. A dropped connection is
// retried with capped backoff from Last-Event-ID, and OnConnect runs before the
// first line, so a caller can catch up there on what the hub no longer holds.
// Only a stream that delivers a line resets the backoff, so a hub that answers
// and closes at once is not retried every second.
package transport

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"mime"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Handler receives stream lifecycle and data callbacks. Any may be nil.
// LastEventID seeds the resume point of the first connection. OnID receives
// each new resume point, after the event's OnData, and "" when an empty id
// clears it. OnEvent replaces OnData and also receives the event's id, or "".
// OnClose receives the life and event count of a stream that ended cleanly.
type Handler struct {
	OnConnect   func()
	OnClose     func(lived time.Duration, events int)
	OnData      func([]byte)
	OnEvent     func(id string, data []byte)
	OnError     func(error)
	OnID        func(string)
	LastEventID string
}

const (
	maxBackoff = 30 * time.Second
	// Just over twice the hub's 40s default heartbeat, so an idle-but-healthy
	// connection is never mistaken for a dead one.
	readTimeout = 90 * time.Second
)

// TokenFunc returns a subscriber JWT for the hub. It is called before every
// connection attempt, so a long-running subscriber survives token expiry:
// subscriber JWTs are short-lived, and reusing one across reconnects would make
// the hub reject every retry once it lapsed.
type TokenFunc func(context.Context) (string, error)

// fatal marks a token error that no retry can fix.
type fatal struct{ err error }

func (f fatal) Error() string { return f.err.Error() }
func (f fatal) Unwrap() error { return f.err }

// Fatal wraps a token error so that Subscribe returns it rather than retry.
func Fatal(err error) error { return fatal{err} }

// Subscribe connects to hubURL for every topic on one connection, obtaining a
// fresh subscriber JWT from token for each attempt, and runs until ctx is
// cancelled. It only returns a non-nil error for unrecoverable setup problems
// and for a token error that Fatal wraps; transient stream failures (and other
// token refresh failures) are reported via Handler.OnError and retried.
func Subscribe(ctx context.Context, hc *http.Client, hubURL string, topics []string, token TokenFunc, h Handler) error {
	if len(topics) == 0 {
		return errors.New("subscribe needs at least one topic")
	}
	endpoint, err := url.Parse(hubURL)
	if err != nil {
		return fmt.Errorf("parse hub url: %w", err)
	}
	q := endpoint.Query()
	q.Del("topic")
	for _, topic := range topics {
		q.Add("topic", topic)
	}
	endpoint.RawQuery = q.Encode()
	target := endpoint.String()

	backoff := time.Second
	// Carried across reconnects so the hub replays anything published while we
	// were disconnected — without it a dropped connection silently loses every
	// notification published during the gap.
	lastID := h.LastEventID
	for {
		if ctx.Err() != nil {
			return ctx.Err()
		}

		jwt, err := token(ctx)
		if err != nil {
			if ctx.Err() != nil {
				return ctx.Err()
			}
			if errors.As(err, new(fatal)) {
				return fmt.Errorf("refresh the subscriber JWT: %w", err)
			}
			if h.OnError != nil {
				h.OnError(fmt.Errorf("refresh the subscriber JWT: %w", err))
			}
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(backoff):
			}
			backoff = min(backoff*2, maxBackoff)

			continue
		}

		var received bool
		lastID, received, err = stream(ctx, hc, target, jwt, lastID, h)
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if err != nil && h.OnError != nil {
			h.OnError(err)
		}
		if received {
			backoff = time.Second
		}

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(backoff):
		}
		backoff = min(backoff*2, maxBackoff)
	}
}

// stream consumes one connection. lastID, when non-empty, is sent as
// Last-Event-ID so the hub replays whatever was published while we were
// disconnected. It returns the most recent id seen so the caller can resume
// from it, and whether the hub sent any line, a heartbeat included.
func stream(ctx context.Context, hc *http.Client, target, jwt, lastID string, h Handler) (string, bool, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, target, nil)
	if err != nil {
		return lastID, false, err
	}
	req.Header.Set("Authorization", "Bearer "+jwt)
	req.Header.Set("Accept", "text/event-stream")
	if lastID != "" {
		req.Header.Set("Last-Event-ID", lastID)
	}

	resp, err := hc.Do(req)
	if err != nil {
		return lastID, false, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return lastID, false, fmt.Errorf("hub returned HTTP %d", resp.StatusCode)
	}
	if err := checkEventStream(resp); err != nil {
		return lastID, false, err
	}
	if h.OnConnect != nil {
		h.OnConnect()
	}
	connectedAt := time.Now()

	sc := bufio.NewScanner(resp.Body)
	sc.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	// A connection can die without the read ever returning: a NAT idle reset or
	// a suspended laptop leaves Scan blocked forever, so nothing reconnects and
	// the bridge looks healthy while receiving nothing. Closing the body from
	// under Scan is what unblocks it. The hub heartbeats every 40s by default,
	// so silence beyond readTimeout means the connection, not the traffic.
	idle := time.AfterFunc(readTimeout, func() { _ = resp.Body.Close() })
	defer idle.Stop()

	var data strings.Builder
	pendingID, haveID := "", false
	received := false
	events := 0
	for sc.Scan() {
		received = true
		idle.Reset(readTimeout)
		line := sc.Text()
		switch {
		case line == "": // event boundary
			if data.Len() > 0 {
				events++
				switch {
				case h.OnEvent != nil:
					id := ""
					if haveID {
						id = pendingID
					}
					h.OnEvent(id, []byte(data.String()))
				case h.OnData != nil:
					h.OnData([]byte(data.String()))
				}
				data.Reset()
			}
			// The resume point moves only once the event has been handed over.
			// Committing it at the `id:` line instead would let a drop between
			// the two skip an event that was never delivered — and because the
			// hub replays from Last-Event-ID, that event is then gone for good.
			if haveID {
				lastID, pendingID, haveID = pendingID, "", false
				if h.OnID != nil {
					h.OnID(lastID)
				}
			}
		case strings.HasPrefix(line, "id:"):
			// An empty `id:` clears the resume point per the SSE spec, so
			// presence is tracked apart from the value.
			pendingID, haveID = strings.TrimPrefix(strings.TrimPrefix(line, "id:"), " "), true
		case strings.HasPrefix(line, "data:"):
			if data.Len() > 0 {
				data.WriteByte('\n')
			}
			data.WriteString(strings.TrimPrefix(strings.TrimPrefix(line, "data:"), " "))
		}
	}

	if err := sc.Err(); err != nil {
		return lastID, received, err
	}
	if !received {
		return lastID, false, fmt.Errorf("stream ended after %s with nothing received", time.Since(connectedAt).Round(100*time.Millisecond))
	}
	if h.OnClose != nil {
		h.OnClose(time.Since(connectedAt), events)
	}

	return lastID, true, nil
}

// checkEventStream refuses a 200 that is not an event stream, such as a login
// page from a proxy. The error names the media type and size, never the body.
func checkEventStream(resp *http.Response) error {
	raw := resp.Header.Get("Content-Type")
	media, _, err := mime.ParseMediaType(raw)
	if err == nil && media == "text/event-stream" {
		return nil
	}
	switch {
	case raw == "":
		media = "no content type"
	case err != nil:
		media = fmt.Sprintf("content type %q", raw)
	}
	size := "unknown length"
	if resp.ContentLength >= 0 {
		size = fmt.Sprintf("%d bytes", resp.ContentLength)
	}

	return fmt.Errorf("hub answered %d with %s (%s), expected text/event-stream", resp.StatusCode, media, size)
}
