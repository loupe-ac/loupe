package api

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestGitHubRepositoryStatusAsksForThePath(t *testing.T) {
	var path, query string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		path, query = r.URL.EscapedPath(), r.URL.RawQuery
		fmt.Fprint(w, `{"path":"acme/api","status":"free"}`)
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "secret", server.Client()).GitHubRepositoryStatus(context.Background(), "p1", "acme/api")
	if err != nil {
		t.Fatal(err)
	}
	if got != "free" || path != "/api/v1/projects/p1/github/repositories/status" || query != "path=acme%2Fapi" {
		t.Fatalf("status = %q, path = %q, query = %q", got, path, query)
	}
}

func TestGitHubRepositoryStatusReportsARefusal(t *testing.T) {
	for _, status := range []int{http.StatusNotFound, http.StatusBadGateway} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(status)
		}))
		_, err := New(server.URL, "secret", server.Client()).GitHubRepositoryStatus(context.Background(), "p1", "acme/api")
		server.Close()
		if err == nil {
			t.Errorf("HTTP %d: want an error", status)
		}
	}
}
