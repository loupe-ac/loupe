package api

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

const artifactProjectID = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"

func TestPushArtifactSendsTheFilesTheFieldsAndTheSession(t *testing.T) {
	var path, auth, session string
	var fields map[string][]string
	var contents, types []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		path, auth, session = r.URL.EscapedPath(), r.Header.Get("Authorization"), r.Header.Get(SessionHeader)
		if err := r.ParseMultipartForm(1 << 20); err != nil {
			t.Error(err)
		}
		fields = r.MultipartForm.Value
		for _, fh := range r.MultipartForm.File["files[]"] {
			f, _ := fh.Open()
			data, _ := io.ReadAll(f)
			contents = append(contents, string(data))
			types = append(types, fh.Header.Get("Content-Type"))
		}
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"artifactId":"a1","versionNumber":2,"path":"/projects/p/artifacts/a1","versionPath":"/projects/p/artifacts/a1/versions/2"}`))
	}))
	t.Cleanup(server.Close)

	stored, err := New(server.URL, "secret", server.Client()).PushArtifact(context.Background(), artifactProjectID, ArtifactPush{
		Name:       "Widget tokens",
		SourceLink: "https://example.test/canvas",
		Card:       "707",
		Session:    "sess-1",
		Files: []ArtifactFile{
			{Path: "index.html", ContentType: "text/html", Contents: []byte("<h1>Hi</h1>")},
			{Path: "css/app.css", ContentType: "text/css", Contents: []byte("body{}")},
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	if path != "/api/projects/"+artifactProjectID+"/artifacts" || auth != "Bearer secret" || session != "sess-1" {
		t.Fatalf("path = %q, auth = %q, session = %q", path, auth, session)
	}
	if got := fields["paths[]"]; len(got) != 2 || got[0] != "index.html" || got[1] != "css/app.css" {
		t.Fatalf("paths = %v", got)
	}
	if fields["name"][0] != "Widget tokens" || fields["card"][0] != "707" || fields["sourceLink"][0] != "https://example.test/canvas" {
		t.Fatalf("fields = %v", fields)
	}
	if _, ok := fields["artifactId"]; ok {
		t.Fatalf("an empty artifactId was sent: %v", fields)
	}
	if len(contents) != 2 || contents[0] != "<h1>Hi</h1>" || contents[1] != "body{}" || types[0] != "text/html" || types[1] != "text/css" {
		t.Fatalf("contents = %v, types = %v", contents, types)
	}
	if stored.ArtifactID != "a1" || stored.VersionNumber != 2 || !strings.HasSuffix(stored.VersionPath, "/versions/2") {
		t.Fatalf("stored = %+v", stored)
	}
}

func TestPushArtifactSendsNoSessionHeaderWithoutASession(t *testing.T) {
	var present bool
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, present = r.Header[SessionHeader]
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{}`))
	}))
	t.Cleanup(server.Close)

	_, err := New(server.URL, "t", server.Client()).PushArtifact(context.Background(), artifactProjectID, ArtifactPush{ArtifactID: "a1"})
	if err != nil || present {
		t.Fatalf("err = %v, header present = %v", err, present)
	}
}

func TestPushArtifactNamesEachRefusal(t *testing.T) {
	for name, tc := range map[string]struct {
		status int
		body   string
		text   string
		is     error
	}{
		"refused":      {http.StatusUnprocessableEntity, `{"error":"artifact_refused","errors":{"session":"Workers read artifacts and do not store them."}}`, "Workers read artifacts", nil},
		"refused bare": {http.StatusUnprocessableEntity, `nope`, "HTTP 422", nil},
		"no project":   {http.StatusNotFound, `{"error":"project_not_found"}`, "project not found", ErrProjectNotFound},
		"old server":   {http.StatusNotFound, ``, "no columns endpoint", ErrEndpointMissing},
		"bad token":    {http.StatusForbidden, ``, "agent scope", nil},
		"rate limit":   {http.StatusTooManyRequests, ``, "rate limited", nil},
		"too large":    {http.StatusRequestEntityTooLarge, ``, "too large", nil},
		"server error": {http.StatusInternalServerError, `boom`, "HTTP 500", nil},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				_, _ = w.Write([]byte(tc.body))
			}))
			t.Cleanup(server.Close)

			_, err := New(server.URL, "t", server.Client()).PushArtifact(context.Background(), artifactProjectID, ArtifactPush{Name: "X"})
			if err == nil || !strings.Contains(err.Error(), tc.text) {
				t.Fatalf("err = %v, want %q", err, tc.text)
			}
			if tc.is != nil && !errors.Is(err, tc.is) {
				t.Fatalf("err = %v, want %v", err, tc.is)
			}
		})
	}
}

func TestAnArtifactRefusalIsATypedError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnprocessableEntity)
		_, _ = w.Write([]byte(`{"errors":{"b":"second","a":"first"}}`))
	}))
	t.Cleanup(server.Close)

	_, err := New(server.URL, "t", server.Client()).PushArtifact(context.Background(), artifactProjectID, ArtifactPush{Name: "X"})
	var refused *ArtifactRefused
	if !errors.As(err, &refused) || refused.Error() != "first; second" {
		t.Fatalf("err = %v", err)
	}
}
