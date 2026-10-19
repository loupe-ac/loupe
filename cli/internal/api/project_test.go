package api

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestCreateProjectPostsOnlyTheFieldsItHolds(t *testing.T) {
	var method, path, auth, contentType, body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		method, path, auth, contentType, body = r.Method, r.URL.Path, r.Header.Get("Authorization"), r.Header.Get("Content-Type"), string(raw)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		fmt.Fprint(w, `{"id":"01a0c0d9-905c-7922-a586-ccc8ce043704","slug":"my-app","name":"My App"}`)
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "secret", server.Client()).CreateProject(context.Background(), CreateProjectRequest{Name: "My App", Remote: "git@github.com:acme/my-app.git"})
	if err != nil {
		t.Fatal(err)
	}
	if method != http.MethodPost || path != "/api/projects" || auth != "Bearer secret" || contentType != "application/json" {
		t.Fatalf("method = %q, path = %q, auth = %q, content type = %q", method, path, auth, contentType)
	}
	var sent map[string]string
	if err := json.Unmarshal([]byte(body), &sent); err != nil {
		t.Fatal(err)
	}
	if len(sent) != 2 || sent["name"] != "My App" || sent["remote"] != "git@github.com:acme/my-app.git" {
		t.Fatalf("body = %s, want name and remote only", body)
	}
	if got.ID != "01a0c0d9-905c-7922-a586-ccc8ce043704" || got.Slug != "my-app" || got.Name != "My App" {
		t.Fatalf("project = %+v", got)
	}
}

func TestCreateProjectMapsTheRefusals(t *testing.T) {
	for name, tc := range map[string]struct {
		status int
		body   string
		want   error
		text   string
	}{
		"limited token": {http.StatusForbidden, `{"error":"credential_not_owner_wide"}`, ErrCredentialNotOwnerWide, ""},
		"name taken":    {http.StatusConflict, `{"error":"name_taken"}`, ErrProjectNameTaken, ""},
		"no name":       {http.StatusUnprocessableEntity, `{"error":"name_required"}`, ErrProjectNameRequired, ""},
		"bad name":      {http.StatusUnprocessableEntity, `{"error":"name_invalid"}`, ErrProjectNameInvalid, ""},
		"other 403":     {http.StatusForbidden, `{}`, nil, "HTTP 403"},
		"server error":  {http.StatusInternalServerError, `boom`, nil, "HTTP 500): boom"},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				fmt.Fprint(w, tc.body)
			}))
			t.Cleanup(server.Close)

			_, err := New(server.URL, "t", server.Client()).CreateProject(context.Background(), CreateProjectRequest{Name: "x"})
			if err == nil {
				t.Fatal("got no error")
			}
			if tc.want != nil && !errors.Is(err, tc.want) {
				t.Fatalf("err = %v, want %v", err, tc.want)
			}
			if tc.text != "" && !strings.Contains(err.Error(), tc.text) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.text)
			}
		})
	}
}
