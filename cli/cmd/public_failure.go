package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

// Exit codes of a public command.
const (
	exitUsage   = 2
	exitAuth    = 4
	exitMissing = 5
	exitRefused = 6
)

const (
	maxRetryWait     = 120 * time.Second
	defaultRetryWait = 60 * time.Second
	codeNotAllowed   = "credential_not_allowed"
)

// apiFailure is a public API answer of status 400 or more, or a body that is
// no answer at all.
type apiFailure struct {
	status int
	// code and detail come from the Problem JSON, and are empty for a body
	// that is not one.
	code, detail string
	// message stands in for code and detail when there is no Problem JSON.
	message string
	// problem is the Problem JSON as the API sent it.
	problem []byte
	exit    int
	// jsonErrors makes stderr hold the Problem JSON alone.
	jsonErrors bool
}

func (f *apiFailure) Error() string {
	if f.code != "" {
		return f.code + ": " + f.detail
	}

	return f.message
}

func (f *apiFailure) ExitCode() int { return f.exit }

// PrintError writes err to w the way main shows it. A failure under
// `--format json` prints the Problem JSON alone.
func PrintError(w io.Writer, err error) {
	var f *apiFailure
	if errors.As(err, &f) && f.jsonErrors && len(f.problem) > 0 {
		fmt.Fprintln(w, strings.TrimSpace(string(f.problem)))

		return
	}
	fmt.Fprintln(w, "error:", err)
}

type problem struct {
	Code   string `json:"code"`
	Detail string `json:"detail"`
	Title  string `json:"title"`
}

// failureOf turns an answer of status 400 or more into the error that sets the
// exit code of R20. A 404 with no Problem JSON comes from a wrong address or a
// server with no public API, so it exits 1 and names the address.
func (s *publicSession) failureOf(resp *api.PublicResponse, o sendOptions) *apiFailure {
	f := &apiFailure{status: resp.Status, jsonErrors: o.jsonErrors}

	var p problem
	if strings.Contains(resp.Header.Get("Content-Type"), "json") && json.Unmarshal(resp.Body, &p) == nil && p.Code != "" {
		f.code, f.detail, f.problem = p.Code, p.Detail, bytes.TrimSpace(resp.Body)
		if f.detail == "" {
			f.detail = p.Title
		}
	} else {
		f.message = fmt.Sprintf("HTTP %d from %s, which is no answer of the Loupe API", resp.Status, s.client.BaseURL())
	}

	switch {
	case resp.Status == http.StatusUnauthorized:
		f.exit = exitAuth
	case resp.Status == http.StatusForbidden && f.code == codeNotAllowed:
		f.exit = exitAuth
		if !s.usesKey {
			f.detail += " Run `loupe login` to sign in again with access to the public API."
		}
	case resp.Status == http.StatusNotFound && f.code == "":
		f.exit = 1
		f.message = fmt.Sprintf("%s answered HTTP 404 and is no Loupe API address: check LOUPE_URL, or the address of `loupe login`", s.client.BaseURL())
	case resp.Status == http.StatusNotFound:
		f.exit = exitMissing
	case resp.Status >= 400 && resp.Status < 500:
		f.exit = exitRefused
	default:
		f.exit = 1
	}

	return f
}

// sendOptions are the shared flags that change how a request is sent.
type sendOptions struct {
	// retry waits for Retry-After on a 429 and sends once more.
	retry bool
	// jsonErrors makes a failure print as Problem JSON.
	jsonErrors bool
	// sleep waits for d, and ends early with the error of ctx. Tests replace it.
	sleep func(ctx context.Context, d time.Duration) error
}

func (o sendOptions) wait(ctx context.Context, d time.Duration) error {
	if o.sleep != nil {
		return o.sleep(ctx, d)
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

// send sends r and gives the answer of status 200 to 299. Any other answer is
// an *apiFailure. A 429 fails at once, unless o.retry waits for it, one time.
func (s *publicSession) send(ctx context.Context, r api.PublicRequest, o sendOptions) (*api.PublicResponse, error) {
	for retried := false; ; retried = true {
		resp, err := s.client.Send(ctx, r)
		if err != nil {
			return nil, &exitError{1, err}
		}
		if resp.Status < 400 {
			return resp, nil
		}

		f := s.failureOf(resp, o)
		if resp.Status != http.StatusTooManyRequests {
			return nil, f
		}

		wait := retryAfter(resp.Header, time.Now())
		f.detail = strings.TrimSpace(f.detail + " Wait " + wait.Round(time.Second).String() + " and send the request again.")
		if f.code == "" {
			f.message = "rate limited (HTTP 429): wait " + wait.Round(time.Second).String() + " and send the request again"
		}
		if !o.retry || retried {
			return nil, f
		}
		if wait > maxRetryWait {
			f.detail = strings.TrimSpace(strings.TrimSuffix(f.detail, " and send the request again.") + ", which is more than the 120 seconds that --retry waits.")

			return nil, f
		}
		if err := o.wait(ctx, wait); err != nil {
			return nil, &exitError{1, err}
		}
	}
}

// retryAfter reads Retry-After as seconds or as a date. Without a usable value
// it assumes the one minute of the rate window.
func retryAfter(h http.Header, now time.Time) time.Duration {
	v := strings.TrimSpace(h.Get("Retry-After"))
	if secs, err := strconv.Atoi(v); err == nil && secs >= 0 {
		return time.Duration(secs) * time.Second
	}
	if at, err := http.ParseTime(v); err == nil {
		return max(at.Sub(now), 0)
	}

	return defaultRetryWait
}
