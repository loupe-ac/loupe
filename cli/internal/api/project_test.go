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
		"limited token":    {http.StatusForbidden, `{"error":"credential_not_owner_wide"}`, ErrCredentialNotOwnerWide, ""},
		"name taken":       {http.StatusConflict, `{"error":"name_taken"}`, ErrProjectNameTaken, ""},
		"name taken, slug": {http.StatusConflict, `{"error":"name_taken","slug":"blog"}`, ErrProjectNameTaken, "blog"},
		"no name":          {http.StatusUnprocessableEntity, `{"error":"name_required"}`, ErrProjectNameRequired, ""},
		"bad name":         {http.StatusUnprocessableEntity, `{"error":"name_invalid"}`, ErrProjectNameInvalid, ""},
		"other 403":        {http.StatusForbidden, `{}`, nil, "HTTP 403"},
		"server error":     {http.StatusInternalServerError, `boom`, nil, "HTTP 500): boom"},
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

func TestCreateProjectPostsDomainAndOrigins(t *testing.T) {
	var body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body = string(raw)
		w.WriteHeader(http.StatusCreated)
		fmt.Fprint(w, `{"id":"p1","slug":"docs","name":"Docs","domain":"docs.example.com","allowedOrigins":["https://docs.example.com"]}`)
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "t", server.Client()).CreateProject(context.Background(), CreateProjectRequest{
		Name: "Docs", Domain: "docs.example.com", Origins: []string{"https://docs.example.com", "https://*.example.com"},
	})
	if err != nil {
		t.Fatal(err)
	}
	var sent map[string]any
	if err := json.Unmarshal([]byte(body), &sent); err != nil {
		t.Fatal(err)
	}
	origins, _ := sent["origins"].([]any)
	if sent["domain"] != "docs.example.com" || len(origins) != 2 || origins[1] != "https://*.example.com" || len(sent) != 3 {
		t.Fatalf("body = %s", body)
	}
	if got.Domain != "docs.example.com" || len(got.AllowedOrigins) != 1 {
		t.Fatalf("project = %+v", got)
	}
}

func TestCreateProjectNameTakenCarriesTheSlug(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusConflict)
		fmt.Fprint(w, `{"error":"name_taken","slug":"blog"}`)
	}))
	t.Cleanup(server.Close)

	_, err := New(server.URL, "t", server.Client()).CreateProject(context.Background(), CreateProjectRequest{Name: "Blog"})
	var taken *NameTakenError
	if !errors.As(err, &taken) || taken.Slug != "blog" {
		t.Fatalf("err = %v, want NameTakenError with slug blog", err)
	}
}

func TestCreateProjectMapsViolationsToTheFlagValues(t *testing.T) {
	body := `{"violations":[` +
		`{"propertyPath":"origins[1]","title":"Use https."},` +
		`{"propertyPath":"origins","title":"Too many origins."},` +
		`{"propertyPath":"domain","title":"Too long."},` +
		`{"propertyPath":"origins[7]","title":"Out of range."}]}`
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/problem+json")
		w.WriteHeader(http.StatusUnprocessableEntity)
		fmt.Fprint(w, body)
	}))
	t.Cleanup(server.Close)

	_, err := New(server.URL, "t", server.Client()).CreateProject(context.Background(), CreateProjectRequest{
		Name: "X", Domain: "d.example.com", Origins: []string{"https://a.example.com", "http://b.example.com"},
	})
	var invalid *InvalidValuesError
	if !errors.As(err, &invalid) {
		t.Fatalf("err = %v, want InvalidValuesError", err)
	}
	want := []InvalidValue{
		{"--origin", "http://b.example.com", "Use https."},
		{"--origin", "https://a.example.com http://b.example.com", "Too many origins."},
		{"--domain", "d.example.com", "Too long."},
		{"origins[7]", "", "Out of range."},
	}
	if len(invalid.Values) != len(want) {
		t.Fatalf("values = %+v", invalid.Values)
	}
	for i, w := range want {
		if invalid.Values[i] != w {
			t.Errorf("value %d = %+v, want %+v", i, invalid.Values[i], w)
		}
	}
	if msg := err.Error(); !strings.Contains(msg, `--origin "http://b.example.com": Use https.`) {
		t.Errorf("message = %q", msg)
	}
}

func TestCreateProjectReadsALong422Body(t *testing.T) {
	var b strings.Builder
	b.WriteString(`{"violations":[`)
	for i := 0; i < 20; i++ {
		if i > 0 {
			b.WriteString(",")
		}
		fmt.Fprintf(&b, `{"propertyPath":"origins[%d]","title":"%s"}`, i, strings.Repeat("x", 200))
	}
	b.WriteString(`]}`)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnprocessableEntity)
		fmt.Fprint(w, b.String())
	}))
	t.Cleanup(server.Close)

	origins := make([]string, 20)
	for i := range origins {
		origins[i] = fmt.Sprintf("http://o%d.example.com", i)
	}
	_, err := New(server.URL, "t", server.Client()).CreateProject(context.Background(), CreateProjectRequest{Name: "X", Origins: origins})
	var invalid *InvalidValuesError
	if !errors.As(err, &invalid) || len(invalid.Values) != 20 {
		t.Fatalf("err = %v, want 20 values", err)
	}
}
