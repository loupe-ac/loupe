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

func TestMintPreviewTokenPostsTheLabelAndTheCard(t *testing.T) {
	var method, path, auth, body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		method, path, auth, body = r.Method, r.URL.EscapedPath(), r.Header.Get("Authorization"), string(raw)
		w.WriteHeader(http.StatusCreated)
		fmt.Fprint(w, `{"token":"tok-1","expiresAt":"2026-10-25T00:00:00+00:00"}`)
	}))
	t.Cleanup(server.Close)

	token, err := New(server.URL, "secret", server.Client()).MintPreviewToken(context.Background(), "p1", "card-707", "c1")
	if err != nil {
		t.Fatal(err)
	}
	if token != "tok-1" || method != http.MethodPost || path != "/api/projects/p1/preview-tokens" || auth != "Bearer secret" {
		t.Fatalf("token = %q, method = %q, path = %q, auth = %q", token, method, path, auth)
	}
	if body != `{"card":"c1","label":"card-707"}` {
		t.Fatalf("body = %s", body)
	}
}

func TestMintPreviewTokenSendsNoCardWhenNoneIsGiven(t *testing.T) {
	var body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body = string(raw)
		w.WriteHeader(http.StatusCreated)
		fmt.Fprint(w, `{"token":"tok-1"}`)
	}))
	t.Cleanup(server.Close)

	if _, err := New(server.URL, "t", server.Client()).MintPreviewToken(context.Background(), "p1", "main", ""); err != nil {
		t.Fatal(err)
	}
	if body != `{"label":"main"}` {
		t.Fatalf("body = %s", body)
	}
}

func TestMintPreviewTokenNamesTheRefusal(t *testing.T) {
	cases := []struct {
		name   string
		status int
		body   string
		check  func(error) bool
	}{
		{"old server", http.StatusNotFound, ``, func(err error) bool { return errors.Is(err, ErrPreviewTokensUnsupported) }},
		{"no project", http.StatusNotFound, `{"error":"project_not_found"}`, func(err error) bool { return errors.Is(err, ErrProjectNotFound) }},
		{"finished card", http.StatusConflict, `{"error":"card_finished"}`, func(err error) bool { return err != nil && contains(err.Error(), "card_finished") }},
		{"rejected", http.StatusUnauthorized, ``, func(err error) bool { return err != nil && contains(err.Error(), "loupe login") }},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				fmt.Fprint(w, tc.body)
			}))
			t.Cleanup(server.Close)

			_, err := New(server.URL, "t", server.Client()).MintPreviewToken(context.Background(), "p1", "l", "")
			if !tc.check(err) {
				t.Fatalf("err = %v", err)
			}
		})
	}
}

func TestRevokePreviewTokensEscapesTheLabelAndCountsTheRevoked(t *testing.T) {
	var method, path string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		method, path = r.Method, r.URL.EscapedPath()
		fmt.Fprint(w, `{"revoked":0}`)
	}))
	t.Cleanup(server.Close)

	n, err := New(server.URL, "t", server.Client()).RevokePreviewTokens(context.Background(), "p1", "a b")
	if err != nil {
		t.Fatal(err)
	}
	if n != 0 || method != http.MethodDelete || path != "/api/projects/p1/preview-tokens/a%20b" {
		t.Fatalf("n = %d, method = %q, path = %q", n, method, path)
	}
}

func contains(s, sub string) bool {
	return strings.Contains(s, sub)
}
