package cmd

import (
	"bytes"
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
)

// runPath puts the process in a repo with no config.json and the run token set.
func runPath(t *testing.T, base string) {
	t.Helper()
	inRepo(t, "")
	dir, err := config.Dir()
	if err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(dir, "config.json")); err == nil {
		t.Fatal("the fixture holds a config.json")
	}
	t.Setenv(runTokenEnv, "run-token")
	t.Setenv(runURLEnv, base)
	t.Setenv(runProjectEnv, projectA)
	t.Setenv("LOUPE_API_KEY", "")
}

func TestRunTokenWithoutURLRefusesWithTheAuthExit(t *testing.T) {
	runPath(t, "")

	for name, run := range map[string]func() error{
		"mcp":    func() error { return runMcp(t) },
		"public": func() error { _, err := publicClient(nil); return err },
	} {
		err := run()
		if code, ok := ExitCode(err); !ok || code != exitAuth || !strings.Contains(err.Error(), runURLEnv) {
			t.Fatalf("%s: got %v, want the auth exit naming %s", name, err, runURLEnv)
		}
	}
}

func TestMcpOnTheRunPathNeedsNoLogin(t *testing.T) {
	runPath(t, "https://loupe.invalid")

	err := runMcp(t, "--project", "my-board")
	if errors.Is(err, config.ErrNotLoggedIn) {
		t.Fatal("mcp read the login with a run token set")
	}
	if err == nil || !strings.Contains(err.Error(), `"my-board"`) {
		t.Fatalf("mcp should reach the project check, got %v", err)
	}
}

func TestRunProjectSourceReadsNoFile(t *testing.T) {
	dir := inRepo(t, "")
	if err := os.WriteFile(filepath.Join(dir, ".loupe.yaml"), []byte("project: [broken\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	t.Setenv(runProjectEnv, strings.ToUpper(projectA))

	project, source, err := runProjectSource("")
	if err != nil {
		t.Fatal(err)
	}
	if project() != projectA || source() != runProjectEnv {
		t.Fatalf("got %q from %q", project(), source())
	}
	project, _, err = runProjectSource(projectB)
	if err != nil || project() != projectB {
		t.Fatalf("flag: %q, %v", project(), err)
	}
	t.Setenv(runProjectEnv, "")
	if _, _, err := runProjectSource(""); err == nil {
		t.Fatal("no project: want an error")
	}
}

func TestPublicCommandOnTheRunPathSendsTheTokenToLoupeURL(t *testing.T) {
	var auth, path string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		auth, path = r.Header.Get("Authorization"), r.URL.Path
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{}`))
	}))
	t.Cleanup(srv.Close)
	runPath(t, srv.URL)

	s, err := publicClient(nil)
	if err != nil {
		t.Fatal(err)
	}
	if s.usesKey {
		t.Fatal("the run token is not an API key")
	}
	resp, err := s.client.Send(context.Background(), api.PublicRequest{Method: http.MethodGet, Path: "/api/v1/projects/x/cards/1"})
	if err != nil {
		t.Fatal(err)
	}
	_ = resp
	if auth != "Bearer run-token" || path != "/api/v1/projects/x/cards/1" {
		t.Fatalf("server saw %q at %q", auth, path)
	}
}

func TestArtifactCreateOnTheRunPathSendsTheTokenAndProject(t *testing.T) {
	var auth, path string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		auth, path = r.Header.Get("Authorization"), r.URL.Path
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"artifactId":"a1","versionNumber":1,"url":"https://app/a1","versionUrl":"https://app/a1/v1"}`))
	}))
	t.Cleanup(srv.Close)
	runPath(t, srv.URL)
	file := filepath.Join(t.TempDir(), "index.html")
	write(t, file, "<h1>Hi</h1>")

	var out bytes.Buffer
	root := newRootCmd()
	root.SetOut(&out)
	root.SetErr(&out)
	root.SetArgs([]string{"artifact", "create", file, "--name", "Mock"})
	if err := root.Execute(); err != nil {
		t.Fatalf("artifact create: %v", err)
	}
	if auth != "Bearer run-token" || path != "/api/v1/projects/"+projectA+"/artifacts" {
		t.Fatalf("server saw %q at %q", auth, path)
	}
	if !strings.Contains(out.String(), "https://app/a1\n") {
		t.Fatalf("stdout = %q", out.String())
	}
}

func TestMcpOnTheRunPathSendsTheTokenAndProject(t *testing.T) {
	var auth, project, path string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if auth == "" {
			auth, project, path = r.Header.Get("Authorization"), r.Header.Get("X-Loupe-Project"), r.URL.Path
		}
		w.WriteHeader(http.StatusInternalServerError)
	}))
	t.Cleanup(srv.Close)
	runPath(t, srv.URL)

	in, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	_, _ = w.WriteString(`{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}` + "\n")
	_ = w.Close()
	stdin := os.Stdin
	os.Stdin = in
	t.Cleanup(func() { os.Stdin = stdin; _ = in.Close() })

	_ = runMcp(t)
	if auth != "Bearer run-token" || project != projectA || path != mcpPath {
		t.Fatalf("server saw %q for project %q at %q", auth, project, path)
	}
}

func TestPreviewTokenOnTheRunPathNeedsNoLogin(t *testing.T) {
	runPath(t, "https://loupe.invalid")

	if _, err := previewTokenClient(previewTokenFlags{}); err != nil {
		t.Fatalf("preview token client: %v", err)
	}
}

func TestWithRunCredentialReplacesInheritedValues(t *testing.T) {
	spec := workerSpec{runToken: "new", runURL: "https://loupe.test", runProject: projectA}
	got := withRunCredential([]string{"A=1", runTokenEnv + "=old", runURLEnv + "=https://other", runProjectEnv + "=x"}, spec)
	want := []string{"A=1", runTokenEnv + "=new", runURLEnv + "=https://loupe.test", runProjectEnv + "=" + projectA}
	if !slices.Equal(got, want) {
		t.Fatalf("env = %q, want %q", got, want)
	}

	in := []string{"A=1", runTokenEnv + "=old"}
	if got := withRunCredential(in, workerSpec{}); !slices.Equal(got, in) {
		t.Fatalf("no token must leave the env, got %q", got)
	}
}

func TestInheritedSessionEnvDropsTheRunToken(t *testing.T) {
	if !slices.Contains(inheritedSessionEnv, runTokenEnv) {
		t.Fatal("a nested bridge would inherit the run token")
	}
}

func TestGiveRunCredential(t *testing.T) {
	p := pending{runID: "run-1"}
	p.event.ProjectID = projectA
	spec := workerSpec{sessionID: "s1"}

	r := &router{bridgeID: "b1", baseURL: "https://loupe.test"}
	if err := r.giveRunCredential(&spec, p); err != nil || spec.runToken != "" {
		t.Fatalf("no issuer: %v %q", err, spec.runToken)
	}

	var gotRun, gotBridge, gotSession string
	r.issueRunCredential = func(_ context.Context, project, runKey, bridgeID, sessionID string) (string, error) {
		gotRun, gotBridge, gotSession = runKey, bridgeID, sessionID

		return "tok", nil
	}
	if err := r.giveRunCredential(&spec, p); err != nil {
		t.Fatal(err)
	}
	if spec.runToken != "tok" || spec.runURL != "https://loupe.test" || spec.runProject != projectA ||
		gotRun != "run-1" || gotBridge != "b1" || gotSession != "s1" {
		t.Fatalf("spec %+v, asked %q %q %q", spec, gotRun, gotBridge, gotSession)
	}

	spec = workerSpec{sessionID: "s1"}
	r.issueRunCredential = func(context.Context, string, string, string, string) (string, error) {
		return "", api.ErrRunCredentialUnsupported
	}
	if err := r.giveRunCredential(&spec, p); err != nil || spec.runToken != "" {
		t.Fatalf("old server must launch with no token: %v %q", err, spec.runToken)
	}

	r.issueRunCredential = func(context.Context, string, string, string, string) (string, error) {
		return "", errors.New("boom")
	}
	if err := r.giveRunCredential(&spec, p); err == nil {
		t.Fatal("a failed mint must fail the launch")
	}
}
