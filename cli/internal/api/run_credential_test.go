package api

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestIssueRunCredential(t *testing.T) {
	var path, body string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		path, body = r.URL.Path, string(b)
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"token":"t","expiresAt":"2026-11-18T00:00:00+00:00"}`))
	}))
	defer srv.Close()

	token, err := New(srv.URL, "k", srv.Client()).IssueRunCredential(context.Background(), "p1", "r1", "b1", "s1")
	if err != nil || token != "t" {
		t.Fatalf("got %q, %v", token, err)
	}
	if path != "/api/v1/bridge/projects/p1/worker-runs/r1/credential" || body != `{"bridgeId":"b1","sessionId":"s1"}` {
		t.Fatalf("sent %s %s", path, body)
	}
}

func TestIssueRunCredentialOldServerAndRefusal(t *testing.T) {
	status, answer := http.StatusNotFound, `{"code":"route_not_found"}`
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(status)
		_, _ = w.Write([]byte(answer))
	}))
	defer srv.Close()
	c := New(srv.URL, "k", srv.Client())

	if _, err := c.IssueRunCredential(context.Background(), "p", "r", "b", "s"); !errors.Is(err, ErrRunCredentialUnsupported) {
		t.Fatalf("404 route_not_found: %v", err)
	}
	status, answer = http.StatusForbidden, `{"code":"credential_not_allowed"}`
	if _, err := c.IssueRunCredential(context.Background(), "p", "r", "b", "s"); err == nil || errors.Is(err, ErrRunCredentialUnsupported) {
		t.Fatalf("403 must fail the launch: %v", err)
	}
}
