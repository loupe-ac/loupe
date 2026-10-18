package api

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestIssueGitTokenPostsTheRepository(t *testing.T) {
	var method, path, auth, body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		method, path, auth, body = r.Method, r.URL.EscapedPath(), r.Header.Get("Authorization"), string(raw)
		w.WriteHeader(http.StatusCreated)
		fmt.Fprint(w, `{"token":"ghs_1","expiresAt":"2026-10-18T14:00:00+00:00"}`)
	}))
	t.Cleanup(server.Close)

	token, expires, err := New(server.URL, "secret", server.Client()).IssueGitToken(context.Background(), "p1", "acme/site")
	if err != nil {
		t.Fatal(err)
	}
	if token != "ghs_1" || expires.UTC().Format("2006-01-02T15:04") != "2026-10-18T14:00" {
		t.Fatalf("token = %q, expires = %v", token, expires)
	}
	if method != http.MethodPost || path != "/api/projects/p1/git-token" || auth != "Bearer secret" || body != `{"repository":"acme/site"}` {
		t.Fatalf("method = %q, path = %q, auth = %q, body = %s", method, path, auth, body)
	}
}

func TestIssueGitTokenNamesTheRefusal(t *testing.T) {
	cases := []struct {
		name   string
		status int
		body   string
		check  func(error) bool
		text   string
	}{
		{"not managed", 403, `{"error":"git_identity_not_managed"}`, func(e error) bool { return errors.Is(e, ErrGitIdentityNotManaged) }, ""},
		{"no route", 404, `<html>`, func(e error) bool { return errors.Is(e, ErrGitTokenUnsupported) }, ""},
		{"no project", 404, `{"error":"project_not_found"}`, func(e error) bool {
			return !errors.Is(e, ErrGitTokenUnsupported) && !errors.Is(e, ErrGitIdentityNotManaged)
		}, "project_not_found"},
		{"missing grant", 409, `{"error":"missing_grant","missing":["contents"]}`, nil, "missing_grant (contents)"},
		{"no installation", 409, `{"error":"no_installation","missing":[]}`, nil, "no_installation"},
		{"github down", 502, `{"error":"github_unavailable","missing":[]}`, nil, "github_unavailable"},
		{"scope", 403, `{"error":"insufficient_scope"}`, func(e error) bool { return !errors.Is(e, ErrGitIdentityNotManaged) }, "insufficient_scope"},
		{"rate limit", 429, ``, nil, "HTTP 429"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(c.status)
				fmt.Fprint(w, c.body)
			}))
			t.Cleanup(server.Close)

			_, _, err := New(server.URL, "t", server.Client()).IssueGitToken(context.Background(), "p1", "acme/site")
			if err == nil {
				t.Fatal("want an error")
			}
			if c.check != nil && !c.check(err) {
				t.Fatalf("err = %v", err)
			}
			if !strings.Contains(err.Error(), c.text) {
				t.Fatalf("err = %q, want %q in it", err, c.text)
			}
		})
	}
}

func TestIssueGitTokenRefusesAnAnswerWithNoToken(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusCreated)
		fmt.Fprint(w, `{"expiresAt":"2026-10-18T14:00:00+00:00"}`)
	}))
	t.Cleanup(server.Close)

	if _, _, err := New(server.URL, "t", server.Client()).IssueGitToken(context.Background(), "p1", "acme/site"); err == nil {
		t.Fatal("want an error")
	}
}
