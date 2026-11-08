package api

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestReportSessionWakesPutsTheWakesOnTheSession(t *testing.T) {
	var path, method, body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		path, method, body = r.URL.EscapedPath(), r.Method, string(b)
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)
	last := time.Date(2026, 9, 13, 9, 0, 0, 0, time.UTC)

	err := New(server.URL, "t", server.Client()).ReportSessionWakes(context.Background(), "loupe", "s 1", []*Wake{
		nil,
		{Trigger: WakeBackfill, LastTurnAt: &last, FirstTurn: &TurnUsage{InputTokens: 1, CacheReadTokens: 2, CacheWriteTokens: 3}},
	})
	if err != nil {
		t.Fatal(err)
	}

	if method != http.MethodPut || path != "/api/v1/bridge/projects/loupe/worker-runs/sessions/s%201/wake" {
		t.Fatalf("request = %s %s", method, path)
	}
	want := `{"wakes":[null,{"trigger":"backfill","lastTurnAt":"2026-09-13T09:00:00Z","firstTurn":{"inputTokens":1,"cacheReadTokens":2,"cacheWriteTokens":3}}]}`
	if body != want {
		t.Fatalf("body = %s", body)
	}
}

func TestReportSessionWakesReadsEachAnswer(t *testing.T) {
	for status, wantErr := range map[int]bool{
		http.StatusNoContent: false, http.StatusOK: false,
		http.StatusNotFound: true, http.StatusUnprocessableEntity: true, http.StatusBadGateway: true,
	} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(status)
		}))
		err := New(server.URL, "t", server.Client()).ReportSessionWakes(context.Background(), "loupe", "s", nil)
		server.Close()
		if (err != nil) != wantErr {
			t.Errorf("status %d: err = %v", status, err)
		}
	}
}
