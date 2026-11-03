package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

const testProjectID = "0192f3c4-5d6e-7f80-9123-456789abcdef"

func problemServer(t *testing.T, status int, code, detail string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/problem+json")
		w.WriteHeader(status)
		_ = json.NewEncoder(w).Encode(map[string]any{"type": "x", "title": "t", "status": status, "code": code, "detail": detail})
	}))
	t.Cleanup(srv.Close)

	return srv
}

func keySession(t *testing.T, base string) *publicSession {
	t.Helper()
	t.Setenv(apiKeyEnv, "key-1")
	t.Setenv("LOUPE_URL", base)
	s, err := publicClient(nil)
	if err != nil {
		t.Fatal(err)
	}

	return s
}

func TestPublicClientKeyReadsNoConfig(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("LOUPE_CONFIG", dir)
	var auth string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		auth = r.Header.Get("Authorization")
		_, _ = w.Write([]byte(`{}`))
	}))
	defer srv.Close()

	s := keySession(t, srv.URL)
	if _, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/projects"}, sendOptions{}); err != nil {
		t.Fatal(err)
	}
	if auth != "Bearer key-1" {
		t.Fatalf("Authorization = %q", auth)
	}
	if entries, _ := os.ReadDir(dir); len(entries) != 0 {
		t.Fatalf("the key path wrote %d entries to the config folder", len(entries))
	}
}

func TestPublicClientNoLoginExitsAuth(t *testing.T) {
	t.Setenv("LOUPE_CONFIG", t.TempDir())
	t.Setenv(apiKeyEnv, "")
	_, err := publicClient(nil)
	if code, ok := ExitCode(err); !ok || code != exitAuth {
		t.Fatalf("err = %v, code = %d %v", err, code, ok)
	}
	if !strings.Contains(err.Error(), "login") || !strings.Contains(err.Error(), apiKeyEnv) {
		t.Fatalf("message = %q", err)
	}
}

func TestFailureExitCodes(t *testing.T) {
	for _, tc := range []struct {
		status int
		code   string
		want   int
	}{
		{401, "invalid_credentials", 4},
		{403, "credential_not_allowed", 4},
		{403, "other", 6},
		{404, "card_not_found", 5},
		{409, "column_target_required", 6},
		{422, "validation_failed", 6},
		{500, "boom", 1},
	} {
		srv := problemServer(t, tc.status, tc.code, "detail text")
		s := keySession(t, srv.URL)
		_, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, sendOptions{})
		code, ok := ExitCode(err)
		if !ok || code != tc.want {
			t.Errorf("%d %s: exit = %d %v, want %d", tc.status, tc.code, code, ok, tc.want)
		}
		if err == nil || err.Error() != tc.code+": "+"detail text" {
			t.Errorf("%d %s: message = %v", tc.status, tc.code, err)
		}
	}
}

func TestNotAllowedOnLoginNamesLoginCommand(t *testing.T) {
	srv := problemServer(t, 403, codeNotAllowed, "No.")
	s := keySession(t, srv.URL)
	s.usesKey = false
	_, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, sendOptions{})
	if err == nil || !strings.Contains(err.Error(), "`loupe login`") {
		t.Fatalf("err = %v", err)
	}
	s.usesKey = true
	if _, err = s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, sendOptions{}); strings.Contains(err.Error(), "loupe login") {
		t.Fatalf("a key must not be told to log in: %v", err)
	}
}

func TestHTMLNotFoundExitsOneAndNamesAddress(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/html")
		w.WriteHeader(404)
		_, _ = w.Write([]byte("<html>nope</html>"))
	}))
	defer srv.Close()
	s := keySession(t, srv.URL)
	_, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, sendOptions{})
	if code, _ := ExitCode(err); code != 1 {
		t.Fatalf("exit = %d, err = %v", code, err)
	}
	if !strings.Contains(err.Error(), srv.URL) {
		t.Fatalf("message must name the address: %v", err)
	}
}

func TestPrintErrorJSONHoldsProblemAlone(t *testing.T) {
	srv := problemServer(t, 404, "card_not_found", "No card.")
	s := keySession(t, srv.URL)
	_, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, sendOptions{jsonErrors: true})

	var out bytes.Buffer
	PrintError(&out, err)
	var got map[string]any
	if json.Unmarshal(out.Bytes(), &got) != nil || got["code"] != "card_not_found" {
		t.Fatalf("stderr = %q", out.String())
	}

	out.Reset()
	_, err = s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, sendOptions{})
	PrintError(&out, err)
	if out.String() != "error: card_not_found: No card.\n" {
		t.Fatalf("stderr = %q", out.String())
	}
}

func rateLimited(t *testing.T, retryAfter string, failures int32) (*httptest.Server, *atomic.Int32) {
	t.Helper()
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		if calls.Add(1) <= failures {
			w.Header().Set("Content-Type", "application/problem+json")
			w.Header().Set("Retry-After", retryAfter)
			w.WriteHeader(429)
			_, _ = w.Write([]byte(`{"type":"x","title":"t","status":429,"code":"rate_limited","detail":"Slow down."}`))

			return
		}
		_, _ = w.Write([]byte(`{"ok":true}`))
	}))
	t.Cleanup(srv.Close)

	return srv, &calls
}

func TestRateLimitFailsAtOnceByDefault(t *testing.T) {
	srv, calls := rateLimited(t, "7", 1)
	s := keySession(t, srv.URL)
	_, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, sendOptions{})
	if code, _ := ExitCode(err); code != exitRefused {
		t.Fatalf("exit = %d, err = %v", code, err)
	}
	if !strings.Contains(err.Error(), "7s") || calls.Load() != 1 {
		t.Fatalf("err = %v, calls = %d", err, calls.Load())
	}
}

func TestRetryWaitsOnceAndSendsAgain(t *testing.T) {
	srv, calls := rateLimited(t, "3", 1)
	s := keySession(t, srv.URL)
	var slept time.Duration
	o := sendOptions{retry: true, sleep: func(_ context.Context, d time.Duration) error { slept = d; return nil }}
	if _, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, o); err != nil {
		t.Fatal(err)
	}
	if slept != 3*time.Second || calls.Load() != 2 {
		t.Fatalf("slept = %v, calls = %d", slept, calls.Load())
	}
}

func TestSecondRateLimitExitsRefused(t *testing.T) {
	srv, calls := rateLimited(t, "1", 2)
	s := keySession(t, srv.URL)
	o := sendOptions{retry: true, sleep: func(context.Context, time.Duration) error { return nil }}
	_, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, o)
	if code, _ := ExitCode(err); code != exitRefused || calls.Load() != 2 {
		t.Fatalf("exit = %d, calls = %d, err = %v", code, calls.Load(), err)
	}
}

func TestRetryAboveLimitFailsAtOnce(t *testing.T) {
	srv, calls := rateLimited(t, "121", 1)
	s := keySession(t, srv.URL)
	o := sendOptions{retry: true, sleep: func(context.Context, time.Duration) error { t.Fatal("must not wait"); return nil }}
	_, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, o)
	if code, _ := ExitCode(err); code != exitRefused || calls.Load() != 1 {
		t.Fatalf("exit = %d, calls = %d", code, calls.Load())
	}
	if !strings.Contains(err.Error(), "2m1s") || !strings.Contains(err.Error(), "120 seconds") {
		t.Fatalf("message must name the wait and the limit: %v", err)
	}
}

func TestRetryAfterForms(t *testing.T) {
	now := time.Date(2026, 11, 2, 12, 0, 0, 0, time.UTC)
	for header, want := range map[string]time.Duration{
		"5": 5 * time.Second,
		now.Add(9 * time.Second).Format(http.TimeFormat): 9 * time.Second,
		"":     defaultRetryWait,
		"soon": defaultRetryWait,
	} {
		h := http.Header{}
		h.Set("Retry-After", header)
		if got := retryAfter(h, now); got != want {
			t.Errorf("%q = %v, want %v", header, got, want)
		}
	}
}

func TestSendRefusesAnswerAbove16MiB(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(bytes.Repeat([]byte("a"), api.MaxPublicBody+1))
	}))
	defer srv.Close()
	s := keySession(t, srv.URL)
	_, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, sendOptions{})
	if code, _ := ExitCode(err); code != 1 {
		t.Fatalf("exit = %d, err = %v", code, err)
	}
}

func TestSendAccepts16MiBExactly(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(bytes.Repeat([]byte("a"), api.MaxPublicBody))
	}))
	defer srv.Close()
	s := keySession(t, srv.URL)
	resp, err := s.send(context.Background(), api.PublicRequest{Method: "GET", Path: "/api/v1/x"}, sendOptions{})
	if err != nil || len(resp.Body) != api.MaxPublicBody {
		t.Fatalf("err = %v", err)
	}
}

func projectListServer(t *testing.T, ids ...string) (*httptest.Server, *atomic.Int32) {
	t.Helper()
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		if r.URL.Path != "/api/v1/projects" || r.URL.Query().Get("limit") != "2" {
			t.Errorf("request = %s", r.URL)
		}
		items := []map[string]string{}
		for _, id := range ids {
			items = append(items, map[string]string{"id": id})
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"items": items, "next": nil})
	}))
	t.Cleanup(srv.Close)

	return srv, &calls
}

func projectCwd(t *testing.T) string {
	t.Helper()
	dir := t.TempDir()
	t.Chdir(dir)
	t.Setenv("LOUPE_CONFIG", t.TempDir())
	t.Setenv(projectEnv, "")

	return dir
}

func TestPublicProjectOrder(t *testing.T) {
	dir := projectCwd(t)
	srv, calls := projectListServer(t, "from-list")
	s := keySession(t, srv.URL)
	ctx := context.Background()

	if err := os.WriteFile(filepath.Join(dir, ".loupe.yaml"), []byte("project: "+testProjectID+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if got, err := publicProject(ctx, s, "", sendOptions{}); err != nil || got != testProjectID {
		t.Fatalf("file: %q %v", got, err)
	}
	t.Setenv(projectEnv, "env-slug")
	if got, _ := publicProject(ctx, s, "", sendOptions{}); got != "env-slug" {
		t.Fatalf("env: %q", got)
	}
	if got, _ := publicProject(ctx, s, "my-slug", sendOptions{}); got != "my-slug" {
		t.Fatalf("flag: %q", got)
	}
	if calls.Load() != 0 {
		t.Fatalf("a named project must not list projects, calls = %d", calls.Load())
	}
}

func TestPublicProjectSingleFromList(t *testing.T) {
	projectCwd(t)
	srv, _ := projectListServer(t, "only")
	s := keySession(t, srv.URL)
	if got, err := publicProject(context.Background(), s, "", sendOptions{}); err != nil || got != "only" {
		t.Fatalf("%q %v", got, err)
	}
}

func TestPublicProjectRefusesNoneAndSeveral(t *testing.T) {
	for name, ids := range map[string][]string{"none": nil, "several": {"a", "b"}} {
		projectCwd(t)
		srv, _ := projectListServer(t, ids...)
		s := keySession(t, srv.URL)
		_, err := publicProject(context.Background(), s, "", sendOptions{})
		if code, _ := ExitCode(err); code != exitUsage {
			t.Fatalf("%s: exit = %d, err = %v", name, code, err)
		}
		for _, way := range []string{"--project", projectEnv, ".loupe.yaml"} {
			if !strings.Contains(err.Error(), way) {
				t.Errorf("%s: message lacks %q: %v", name, way, err)
			}
		}
	}
}

func TestPublicProjectBrokenFileDoesNotFallThrough(t *testing.T) {
	dir := projectCwd(t)
	srv, calls := projectListServer(t, "only")
	s := keySession(t, srv.URL)
	if err := os.WriteFile(filepath.Join(dir, ".loupe.yaml"), []byte("project: nope\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	_, err := publicProject(context.Background(), s, "", sendOptions{})
	if code, _ := ExitCode(err); code != exitUsage || calls.Load() != 0 {
		t.Fatalf("exit = %d, calls = %d, err = %v", code, calls.Load(), err)
	}
}

func TestPublicProjectListFailureKeepsItsCode(t *testing.T) {
	projectCwd(t)
	srv := problemServer(t, 401, "invalid_credentials", "Bad key.")
	s := keySession(t, srv.URL)
	_, err := publicProject(context.Background(), s, "", sendOptions{})
	if code, _ := ExitCode(err); code != exitAuth {
		t.Fatalf("exit = %d, err = %v", code, err)
	}
}
