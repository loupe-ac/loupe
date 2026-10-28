package api

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestCloseInteractiveSessionPostsToTheSessionPath(t *testing.T) {
	var gotMethod, gotPath, gotAuth string
	var gotBody []byte
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotMethod, gotPath, gotAuth = r.Method, r.URL.EscapedPath(), r.Header.Get("Authorization")
		gotBody, _ = io.ReadAll(r.Body)
		fmt.Fprint(w, `{"closed":2}`)
	}))
	t.Cleanup(server.Close)

	n, err := New(server.URL, "tok", server.Client()).CloseInteractiveSession(context.Background(), "a/b")
	if err != nil {
		t.Fatal(err)
	}
	if n != 2 || gotMethod != http.MethodPost || gotPath != "/api/interactive-sessions/a%2Fb/close" || gotAuth != "Bearer tok" || len(gotBody) != 0 {
		t.Fatalf("n=%d method=%s path=%s auth=%s body=%q", n, gotMethod, gotPath, gotAuth, gotBody)
	}
}

func TestCloseInteractiveSessionReportsARefusal(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		http.Error(w, "nope", http.StatusForbidden)
	}))
	t.Cleanup(server.Close)

	_, err := New(server.URL, "t", server.Client()).CloseInteractiveSession(context.Background(), "s")
	if err == nil || !strings.Contains(err.Error(), "403") {
		t.Fatalf("err = %v", err)
	}
}

func TestHeartbeatSendsSessionReadingsAndReadsTheWatchList(t *testing.T) {
	var got map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&got)
		fmt.Fprint(w, `{"watchSessions":["s1","s2",7]}`)
	}))
	t.Cleanup(server.Close)

	yes := true
	at := time.Date(2026, 10, 28, 9, 0, 0, 0, time.UTC)
	reply, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{
		InteractiveSessions: []InteractiveSessionReading{{SessionID: "s1", Alive: &yes, TranscriptAt: &at}, {SessionID: "s2"}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if len(reply.WatchSessions) != 2 || reply.WatchSessions[0] != "s1" || reply.WatchSessions[1] != "s2" {
		t.Fatalf("watch = %v", reply.WatchSessions)
	}
	b, _ := json.Marshal(got["interactiveSessions"])
	want := `[{"alive":true,"sessionId":"s1","transcriptAt":"2026-10-28T09:00:00Z"},{"alive":null,"sessionId":"s2","transcriptAt":null}]`
	if string(b) != want {
		t.Fatalf("sessions = %s", b)
	}
}

func TestHeartbeatOmitsTheSessionKeyWhenNothingIsWatched(t *testing.T) {
	var got map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&got)
		fmt.Fprint(w, `{}`)
	}))
	t.Cleanup(server.Close)

	if _, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{}); err != nil {
		t.Fatal(err)
	}
	if _, ok := got["interactiveSessions"]; ok {
		t.Fatalf("body = %v", got)
	}
}
