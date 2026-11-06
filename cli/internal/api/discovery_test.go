package api

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestStartDiscoveryPostsAndParsesTheAnswer(t *testing.T) {
	for name, body := range map[string]string{
		"flat":    `{"runId":"r1","cardNumber":7,"cardId":"c1","url":"https://x/r"}`,
		"wrapped": `{"data":{"runId":"r1","cardNumber":7,"cardId":"c1","url":"https://x/r"}}`,
	} {
		t.Run(name, func(t *testing.T) {
			var method, path string
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				method, path = r.Method, r.URL.Path
				w.WriteHeader(http.StatusAccepted)
				_, _ = w.Write([]byte(body))
			}))
			defer srv.Close()

			d, err := New(srv.URL, "key", srv.Client()).StartDiscovery(context.Background(), "my proj")
			if err != nil {
				t.Fatal(err)
			}
			if method != http.MethodPost || path != "/api/v1/projects/my proj/discovery" {
				t.Errorf("request = %s %s", method, path)
			}
			if d.RunID != "r1" || d.CardNumber != 7 || d.CardID != "c1" || d.URL != "https://x/r" {
				t.Errorf("discovery = %+v", d)
			}
		})
	}
}

func TestStartDiscoveryReportsAFailure(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusConflict)
		_, _ = w.Write([]byte(`{"title":"Conflict","detail":"Discovery runs on card 4."}`))
	}))
	defer srv.Close()

	_, err := New(srv.URL, "key", srv.Client()).StartDiscovery(context.Background(), "p")
	if err == nil || !strings.Contains(err.Error(), "HTTP 409: Conflict Discovery runs on card 4.") {
		t.Fatalf("err = %v", err)
	}
}
