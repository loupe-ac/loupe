package api

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestSessionCardRunReadsTheAnswer(t *testing.T) {
	tests := []struct {
		name string
		body string
		want CardRun
	}{
		{"lifecycle with an open run", `{"template":"lifecycle","openCardRun":true}`, CardRun{Template: "lifecycle", Open: true}},
		{"lifecycle without a run", `{"template":"lifecycle","openCardRun":false}`, CardRun{Template: "lifecycle"}},
		{"another template", `{"template":"kanban","openCardRun":false}`, CardRun{Template: "kanban"}},
		{"no template", `{"template":null,"openCardRun":false}`, CardRun{}},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			var gotMethod, gotPath, gotAuth, gotProject string
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				gotMethod, gotPath, gotAuth = r.Method, r.URL.EscapedPath(), r.Header.Get("Authorization")
				gotProject = r.Header.Get(ProjectHeader)
				fmt.Fprint(w, tc.body)
			}))
			t.Cleanup(server.Close)

			got, err := New(server.URL, "tok", server.Client()).SessionCardRun(context.Background(), "a/b", "proj-1")
			if err != nil {
				t.Fatal(err)
			}
			if got != tc.want {
				t.Fatalf("got %+v, want %+v", got, tc.want)
			}
			if gotMethod != http.MethodGet || gotPath != "/api/interactive-sessions/a%2Fb/card-run" || gotAuth != "Bearer tok" || gotProject != "proj-1" {
				t.Fatalf("method=%s path=%s auth=%s project=%s", gotMethod, gotPath, gotAuth, gotProject)
			}
		})
	}
}

func TestSessionCardRunFailsOnAnythingButOK(t *testing.T) {
	for _, status := range []int{404, 403, 422, 429, 500} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			http.Error(w, "no", status)
		}))
		_, err := New(server.URL, "t", server.Client()).SessionCardRun(context.Background(), "s", "p")
		server.Close()
		if err == nil {
			t.Errorf("status %d: want an error", status)
		}
	}
}

func TestSessionCardRunFailsOnBadJSON(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprint(w, `not json`)
	}))
	t.Cleanup(server.Close)

	if _, err := New(server.URL, "t", server.Client()).SessionCardRun(context.Background(), "s", "p"); err == nil {
		t.Fatal("want an error")
	}
}
