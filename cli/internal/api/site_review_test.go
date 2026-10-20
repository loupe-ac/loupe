package api

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

const reviewProject = "0192f3c4-5d6e-7f80-9123-456789abcdef"

func TestSiteReviewSendsTheProjectOnlyWhenGiven(t *testing.T) {
	var gotHeader, gotQuery, gotBody, gotType string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotHeader = r.Header.Get(ProjectHeader)
		gotQuery = r.URL.RawQuery
		gotType = r.Header.Get("Content-Type")
		data, _ := io.ReadAll(r.Body)
		gotBody = string(data)
		switch r.URL.Path {
		case "/api/board/cards":
			w.Write([]byte(`{"cards":[{"number":3}]}`))
		case "/api/projects":
			w.Write([]byte(`{"sites":[]}`))
		default:
			w.WriteHeader(http.StatusCreated)
			w.Write([]byte(`{"ok":true}`))
		}
	}))
	t.Cleanup(server.Close)
	client := New(server.URL, "t", server.Client())
	ctx := context.Background()

	cards, err := client.WithProject(reviewProject).SiteReviewTargets(ctx, "a b", false)
	if err != nil || string(cards) != `[{"number":3}]` {
		t.Fatalf("targets = %s, %v", cards, err)
	}
	if gotHeader != reviewProject || gotQuery != "q=a+b" {
		t.Fatalf("targets sent header %q query %q", gotHeader, gotQuery)
	}
	if _, err := client.WithProject(reviewProject).SiteReviewTargets(ctx, "", true); err != nil || gotQuery != "parent=1" {
		t.Fatalf("parent targets: %v, query %q", err, gotQuery)
	}
	if _, err := client.WithProject(reviewProject).SiteReviewTargets(ctx, "", false); err != nil || gotQuery != "" {
		t.Fatalf("plain targets: %v, query %q", err, gotQuery)
	}

	if _, err := client.WithProject(reviewProject).SiteReviewNotes(ctx, "card:"+reviewProject); err != nil || gotQuery != "scope=card%3A"+reviewProject+"&surface=ios-simulator" || gotHeader != reviewProject {
		t.Fatalf("notes: %v, query %q, header %q", err, gotQuery, gotHeader)
	}
	if _, err := client.WithProject(reviewProject).SiteReviewNotes(ctx, ""); err != nil || gotQuery != "scope=&surface=ios-simulator" {
		t.Fatalf("an empty scope must still be sent, query %q", gotQuery)
	}

	answer, err := client.WithProject(reviewProject).SaveSiteReviewNote(ctx, []byte(`{"a":1}`))
	if err != nil || string(answer) != `{"ok":true}` || gotBody != `{"a":1}` || gotType != "application/json" {
		t.Fatalf("save = %s, %v, body %q, type %q", answer, err, gotBody, gotType)
	}

	if _, err := client.WithProject(reviewProject).Sites(ctx); err != nil || gotHeader != "" {
		t.Fatalf("sites sent header %q, err %v", gotHeader, err)
	}
	if _, err := client.SiteReviewNotes(ctx, ""); err != nil || gotHeader != "" {
		t.Fatalf("a client with no project sent header %q", gotHeader)
	}
}

func TestSiteReviewMapsRefusals(t *testing.T) {
	cases := map[string]struct {
		status int
		body   string
		want   error
	}{
		"not bound":    {http.StatusForbidden, `{"error":"token_not_bound_to_site"}`, ErrProjectNotCovered},
		"other 403":    {http.StatusForbidden, `{"error":"insufficient_scope"}`, ErrScopeMissing},
		"unauthorized": {http.StatusUnauthorized, `{}`, ErrScopeMissing},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				w.Write([]byte(tc.body))
			}))
			t.Cleanup(server.Close)
			client := New(server.URL, "t", server.Client()).WithProject(reviewProject)

			if _, err := client.SiteReviewNotes(context.Background(), ""); !errors.Is(err, tc.want) {
				t.Fatalf("notes err = %v, want %v", err, tc.want)
			}
			if _, err := client.SaveSiteReviewNote(context.Background(), []byte(`{}`)); !errors.Is(err, tc.want) {
				t.Fatalf("save err = %v, want %v", err, tc.want)
			}
			if _, err := client.SiteReviewTargets(context.Background(), "", false); !errors.Is(err, tc.want) {
				t.Fatalf("targets err = %v, want %v", err, tc.want)
			}
		})
	}
}

func TestSiteReviewReportsOtherFailures(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
		w.Write([]byte("boom"))
	}))
	t.Cleanup(server.Close)

	_, err := New(server.URL, "t", server.Client()).SiteReviewNotes(context.Background(), "")
	if err == nil || errors.Is(err, ErrScopeMissing) || errors.Is(err, ErrProjectNotCovered) {
		t.Fatalf("err = %v, want a plain failure", err)
	}
}
