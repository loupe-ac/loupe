package cmd

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/profile"
	"github.com/ubermuda/loupe/cli/internal/projectfile"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// initWorld is a fake Loupe instance and the programs init runs.
type initWorld struct {
	server  *httptest.Server
	sites   string
	created atomic.Int32
	// createBody is what the server received on POST /api/projects.
	createBody atomic.Value

	execCalls []string
	// execFail names the commands whose run fails, by their first words.
	execFail map[string]bool
	// execOut gives the output of a command, by its words.
	execOut map[string]string
	claude  bool

	bridgeStarts int
	bridgeErr    error
	bridgeUp     bool
	bridgeFlags  []string
	reloads      int
	reloadErr    error
}

func newInitWorld(t *testing.T, sites string) *initWorld {
	t.Helper()
	w := &initWorld{sites: sites, execFail: map[string]bool{}, execOut: map[string]string{}}
	w.server = httptest.NewServer(http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
		rw.Header().Set("Content-Type", "application/json")
		switch {
		case r.URL.Path == "/oauth/device-authorization":
			fmt.Fprint(rw, `{"device_code":"dev-1","user_code":"BCDFGHJK","verification_uri":"https://loupe.test/d","verification_uri_complete":"https://loupe.test/d?c=BCDFGHJK","expires_in":600,"interval":1}`)
		case r.URL.Path == "/oauth/token":
			fmt.Fprint(rw, `{"token_type":"Bearer","access_token":"eyJ.access","refresh_token":"refresh-1","expires_in":3600}`)
		case r.URL.Path == "/api/projects" && r.Method == http.MethodGet:
			fmt.Fprintf(rw, `{"sites":[%s]}`, w.sites)
		case r.URL.Path == "/api/projects" && r.Method == http.MethodPost:
			w.created.Add(1)
			var body map[string]string
			_ = json.NewDecoder(r.Body).Decode(&body)
			w.createBody.Store(body)
			rw.WriteHeader(http.StatusCreated)
			fmt.Fprintf(rw, `{"id":%q,"slug":"made","name":"Made"}`, firstProject)
		default:
			t.Errorf("unexpected request %s %s", r.Method, r.URL.Path)
		}
	}))
	t.Cleanup(w.server.Close)

	oldLook, oldGOOS, oldExec, oldStart, oldRuns, oldReload := initLookPath, initGOOS, initExec, initStartBridge, initBridgeRuns, initReloadBridge
	t.Cleanup(func() {
		initLookPath, initGOOS, initExec, initStartBridge, initBridgeRuns, initReloadBridge = oldLook, oldGOOS, oldExec, oldStart, oldRuns, oldReload
	})
	initGOOS = "darwin"
	initLookPath = func(name string) (string, error) {
		if name == "claude" && w.claude {
			return "/bin/claude", nil
		}

		return "", errors.New("not found")
	}
	initExec = func(_ context.Context, _ string, name string, args ...string) ([]byte, error) {
		line := strings.Join(append([]string{name}, args...), " ")
		w.execCalls = append(w.execCalls, line)
		out := w.execOut[line]
		for prefix := range w.execFail {
			if strings.HasPrefix(line, prefix) {
				return []byte(out), errors.New("exit status 1")
			}
		}

		return []byte(out), nil
	}
	initStartBridge = func(cmd *cobra.Command, o bridgeRunOptions) error {
		w.bridgeStarts++
		cmd.Flags().Visit(func(f *pflag.Flag) { w.bridgeFlags = append(w.bridgeFlags, f.Name+"="+f.Value.String()) })
		if w.bridgeErr == nil {
			w.bridgeUp = true
		}

		return w.bridgeErr
	}
	initBridgeRuns = func(string) bool { return w.bridgeUp }
	initReloadBridge = func(string) error {
		w.reloads++

		return w.reloadErr
	}

	return w
}

func (w *initWorld) called(prefix string) bool {
	for _, c := range w.execCalls {
		if strings.HasPrefix(c, prefix) {
			return true
		}
	}

	return false
}

func devSite() string {
	return `{"id":"` + firstProject + `","slug":"dev","name":"Dev Project"}`
}

func runInitCmd(t *testing.T, answers string, args ...string) (string, error) {
	t.Helper()
	var out bytes.Buffer
	cmd := newInitCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetIn(bufio.NewReader(strings.NewReader(answers)))
	cmd.SetArgs(args)
	err := cmd.Execute()

	return out.String(), err
}

func readOrEmpty(t *testing.T, path string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		t.Fatal(err)
	}

	return string(b)
}

func TestInitRunsEveryStepOnAFreshRepository(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()

	out, err := runInitCmd(t, "y\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	for _, want := range []string{
		"Sign in: skipped", "Project: done, using Dev Project", "Link: done", "Plugin: skipped",
		"Rules: done", "Turn on automatic updates? [y/N]", "Auto-update: done, automatic updates are on",
		"Bridge: done", "Playwright: skipped",
		"Open " + w.server.URL + "/welcome/connect", "Commit " + profile.Path,
	} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	file, err := projectfile.Load(dir)
	if err != nil || file.Project != firstProject {
		t.Errorf("project file = %+v, %v", file, err)
	}
	body := readOrEmpty(t, path)
	for _, want := range []string{"dev:", "dir: " + dir, "autoUpdate: true", "appPrompts: true"} {
		if !strings.Contains(body, want) {
			t.Errorf("rule file lacks %q:\n%s", want, body)
		}
	}
	if info, err := os.Stat(path); err != nil || info.Mode().Perm() != 0o600 {
		t.Errorf("rule file mode = %v, %v; want 0600", info, err)
	}
	if w.bridgeStarts != 1 {
		t.Errorf("bridge starts = %d, want 1", w.bridgeStarts)
	}
}

func TestInitASecondRunChangesNothing(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	if out, err := runInitCmd(t, "n\n", "--no-mcp"); err != nil {
		t.Fatalf("first run: %v\n%s", err, out)
	}
	before := map[string]string{
		"rules":   readOrEmpty(t, path),
		"project": readOrEmpty(t, filepath.Join(dir, projectfile.Name)),
		"profile": readOrEmpty(t, filepath.Join(dir, profile.Path)),
	}

	out, err := runInitCmd(t, "", "--no-mcp")
	if err != nil {
		t.Fatalf("second run: %v\n%s", err, out)
	}

	after := map[string]string{
		"rules":   readOrEmpty(t, path),
		"project": readOrEmpty(t, filepath.Join(dir, projectfile.Name)),
		"profile": readOrEmpty(t, filepath.Join(dir, profile.Path)),
	}
	for name := range before {
		if before[name] != after[name] {
			t.Errorf("the second run changed the %s file", name)
		}
	}
	for _, want := range []string{"Project: skipped", "Link: skipped", "Rules: skipped", "Auto-update: skipped", "Bridge: skipped"} {
		if !strings.Contains(out, want) {
			t.Errorf("second run lacks %q:\n%s", want, out)
		}
	}
	if w.bridgeStarts != 1 || w.reloads != 0 {
		t.Errorf("bridge starts = %d, reloads = %d, want 1 and 0", w.bridgeStarts, w.reloads)
	}
	if strings.Contains(readOrEmpty(t, path), "autoUpdate: true") {
		t.Error("an answer of no turned automatic updates on")
	}
}

func TestInitSignsInWhenNoLoginIsStored(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, "")

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--url", w.server.URL)
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Sign in: done") || !strings.Contains(out, "BCDF-GHJK") {
		t.Errorf("want the sign-in step, got:\n%s", out)
	}
	if stored, err := config.Load(); err != nil || stored.BaseURL != w.server.URL {
		t.Errorf("stored login = %+v, %v", stored, err)
	}
	if strings.Contains(out, "eyJ.access") {
		t.Error("init printed a token")
	}
}

func TestInitRefusesAURLForAnotherInstanceThanTheStoredLogin(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, "https://a.loupe.test")

	for _, args := range [][]string{{"--no-mcp", "--url", w.server.URL}, {"--no-mcp"}} {
		if len(args) == 1 {
			t.Setenv("LOUPE_URL", w.server.URL+"/")
		}
		out, err := runInitCmd(t, "n\n", args...)
		if err == nil || !strings.Contains(err.Error(), "signed in to https://a.loupe.test, not "+w.server.URL) || !strings.Contains(err.Error(), "loupe login --url "+w.server.URL) {
			t.Errorf("init %v: want a refusal that names both instances, got %v\n%s", args, err, out)
		}
		if strings.Contains(out, "Project:") {
			t.Errorf("init %v went on past sign-in:\n%s", args, out)
		}
	}
	if _, err := os.Stat(filepath.Join(dir, projectfile.Name)); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("init wrote %s: %v", projectfile.Name, err)
	}
	if stored, err := config.Load(); err != nil || stored.BaseURL != "https://a.loupe.test" {
		t.Errorf("stored login = %+v, %v", stored, err)
	}
}

func TestInitAcceptsAURLThatNamesTheStoredLogin(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--url", w.server.URL+"/")
	if err != nil || !strings.Contains(out, "Sign in: skipped") {
		t.Errorf("init: %v\n%s", err, out)
	}
}

func TestInitKeepsTheProjectFileAndUsesTheFlagOverTheList(t *testing.T) {
	w := newInitWorld(t, devSite()+`,{"id":"`+secondProject+`","slug":"other","name":"Other"}`)
	dir := inRepo(t, w.server.URL)
	if err := projectfile.Write(dir, secondProject); err != nil {
		t.Fatal(err)
	}

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Project: skipped, "+projectfile.Name+" names Other") || !strings.Contains(out, "Link: skipped") {
		t.Errorf("want the file kept, got:\n%s", out)
	}
	if body := readOrEmpty(t, filepath.Join(dir, projectfile.Name)); !strings.Contains(body, secondProject) {
		t.Errorf("project file changed: %s", body)
	}
	path, _ := defaultRulesPath()
	if body := readOrEmpty(t, path); !strings.Contains(body, "other:") {
		t.Errorf("rule file must map the kept project:\n%s", body)
	}
}

func TestInitRefusesAProjectFlagThatDiffersFromTheProjectFile(t *testing.T) {
	w := newInitWorld(t, devSite()+`,{"id":"`+secondProject+`","slug":"other","name":"Other"}`)
	dir := inRepo(t, w.server.URL)
	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatal(err)
	}

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--project", secondProject)
	if err == nil || !strings.Contains(err.Error(), "names Dev Project") || !strings.Contains(err.Error(), "--force") {
		t.Fatalf("err = %v, want a refusal that names the linked project\n%s", err, out)
	}
	path, _ := defaultRulesPath()
	if body := readOrEmpty(t, path); body != "" {
		t.Errorf("init wrote a rule file after the refusal:\n%s", body)
	}

	out, err = runInitCmd(t, "n\n", "--no-mcp", "--project", strings.ToUpper(firstProject))
	if err != nil || !strings.Contains(out, "Link: skipped") {
		t.Errorf("a flag that names the linked project must pass: %v\n%s", err, out)
	}
}

func TestInitAcceptsAProjectSlug(t *testing.T) {
	w := newInitWorld(t, devSite()+`,{"id":"`+secondProject+`","slug":"other","name":"Other"}`)
	dir := inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--project", "other")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if file, _ := projectfile.Load(dir); file.Project != secondProject {
		t.Errorf("project = %q, want %q", file.Project, secondProject)
	}
	path, _ := defaultRulesPath()
	if body := readOrEmpty(t, path); !strings.Contains(body, "other:") {
		t.Errorf("rule file must map the project by its slug:\n%s", body)
	}

	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatal(err)
	}
	if out, err := runInitCmd(t, "n\n", "--no-mcp", "--project", "dev"); err != nil || !strings.Contains(out, "Link: skipped") {
		t.Errorf("a slug that names the linked project must pass: %v\n%s", err, out)
	}
}

func TestInitRefusesAnUnknownProjectSlug(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--project", "acme")
	if err == nil || !strings.Contains(err.Error(), `"acme"`) {
		t.Fatalf("err = %v, want a refusal that names the slug\n%s", err, out)
	}
}

func TestInitRefusesAProjectIDThatTheLoginDoesNotList(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--project", secondProject)
	if err == nil || !strings.Contains(err.Error(), secondProject) {
		t.Fatalf("err = %v, want a refusal that names the id\n%s", err, out)
	}
	if _, err := os.Stat(filepath.Join(dir, projectfile.Name)); !os.IsNotExist(err) {
		t.Errorf("init wrote %s for an unlisted project", projectfile.Name)
	}
	path, _ := defaultRulesPath()
	if body := readOrEmpty(t, path); body != "" {
		t.Errorf("init wrote a rule file for an unlisted project:\n%s", body)
	}
}

func TestInitRefusesAProjectFileThatNamesAnUnlistedProject(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	if err := projectfile.Write(dir, secondProject); err != nil {
		t.Fatal(err)
	}

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err == nil || !strings.Contains(err.Error(), secondProject) || !strings.Contains(err.Error(), "--force") {
		t.Fatalf("err = %v, want a refusal that names the id and --force\n%s", err, out)
	}
	path, _ := defaultRulesPath()
	if body := readOrEmpty(t, path); body != "" {
		t.Errorf("init wrote a rule file for an unlisted project:\n%s", body)
	}
}

func TestInitWithProjectAndForceReplacesTheFile(t *testing.T) {
	w := newInitWorld(t, devSite()+`,{"id":"`+secondProject+`","slug":"other","name":"Other"}`)
	dir := inRepo(t, w.server.URL)
	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatal(err)
	}

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--force", "--project", secondProject)
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if file, _ := projectfile.Load(dir); file.Project != secondProject {
		t.Errorf("project = %q, want %q", file.Project, secondProject)
	}
	if !strings.Contains(out, "Link: done") {
		t.Errorf("want Link done:\n%s", out)
	}
}

func TestInitCreatesAProjectFromTheOriginRemoteWhenTheLoginCoversNone(t *testing.T) {
	w := newInitWorld(t, "")
	inRepo(t, w.server.URL)
	w.execOut["git remote get-url origin"] = "git@github.com:acme/app.git\n"

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.created.Load() != 1 {
		t.Fatalf("created = %d, want 1", w.created.Load())
	}
	if body, _ := w.createBody.Load().(map[string]string); body["remote"] != "git@github.com:acme/app.git" {
		t.Errorf("create body = %v", body)
	}
	if !strings.Contains(out, "Project: done, created Made") {
		t.Errorf("output:\n%s", out)
	}
	path, _ := defaultRulesPath()
	if body := readOrEmpty(t, path); !strings.Contains(body, "made:") {
		t.Errorf("rule file must map the new slug:\n%s", body)
	}
}

func TestInitSendsAndPrintsTheRemoteWithoutItsCredentials(t *testing.T) {
	for _, remote := range []string{
		"https://bob:s3cret@github.com/acme/app.git\n",
		"https://bob:s3cret%@github.com/acme/app.git\n",
	} {
		t.Run(remote, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
				rw.Header().Set("Content-Type", "application/json")
				if r.Method == http.MethodGet {
					fmt.Fprint(rw, `{"sites":[]}`)

					return
				}
				if body, _ := io.ReadAll(r.Body); strings.Contains(string(body), "s3cret") {
					t.Errorf("the create request carries the token: %s", body)
				}
				rw.WriteHeader(http.StatusConflict)
				fmt.Fprint(rw, `{"error":"name_taken"}`)
			}))
			t.Cleanup(server.Close)
			w := newInitWorld(t, "")
			inRepo(t, server.URL)
			w.execOut["git remote get-url origin"] = remote

			out, err := runInitCmd(t, "", "--no-mcp")
			if err == nil {
				t.Fatalf("init must fail on the refusal:\n%s", out)
			}
			if strings.Contains(err.Error()+out, "s3cret") || strings.Contains(err.Error(), "bob@") {
				t.Errorf("the error shows the credentials: %v\n%s", err, out)
			}
			if !strings.Contains(err.Error(), "https://github.com/acme/app.git") {
				t.Errorf("err = %v, want the remote without its credentials", err)
			}
		})
	}
}

func TestWithoutUserinfoKeepsARemoteWithNoCredentials(t *testing.T) {
	for in, want := range map[string]string{
		"https://x-access-token:tok@github.com/acme/app.git": "https://github.com/acme/app.git",
		"https://github.com/acme/app.git":                    "https://github.com/acme/app.git",
		"git@github.com:acme/app.git":                        "git@github.com:acme/app.git",
		"ssh://git@github.com/acme/app.git":                  "ssh://github.com/acme/app.git",
		"https://bob:se%cret@github.com/acme/app.git":        "https://github.com/acme/app.git",
		"https://bob:p@ss%zz@github.com":                     "https://github.com",
	} {
		if got := withoutUserinfo(in); got != want {
			t.Errorf("withoutUserinfo(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestInitAsksForAProjectWhenThereIsNoRemote(t *testing.T) {
	w := newInitWorld(t, "")
	dir := inRepo(t, w.server.URL)
	w.execFail["git remote"] = true

	out, err := runInitCmd(t, "", "--no-mcp")
	if err == nil {
		t.Fatalf("want an error:\n%s", out)
	}
	for _, want := range []string{"origin remote", "--project", w.server.URL} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("error %q lacks %q", err, want)
		}
	}
	if w.created.Load() != 0 {
		t.Error("init created a project with no remote")
	}
	if _, statErr := os.Stat(filepath.Join(dir, projectfile.Name)); statErr == nil {
		t.Error("init wrote a project file")
	}
}

func TestInitReportsAServerRefusalToCreateAProject(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
		rw.Header().Set("Content-Type", "application/json")
		if r.Method == http.MethodGet {
			fmt.Fprint(rw, `{"sites":[]}`)

			return
		}
		rw.WriteHeader(http.StatusConflict)
		fmt.Fprint(rw, `{"error":"name_taken"}`)
	}))
	t.Cleanup(server.Close)
	w := newInitWorld(t, "")
	inRepo(t, server.URL)
	w.execOut["git remote get-url origin"] = "git@github.com:acme/app.git"

	_, err := runInitCmd(t, "", "--no-mcp")
	if err == nil || !strings.Contains(err.Error(), "--project") {
		t.Fatalf("err = %v, want advice to pass --project", err)
	}
}

func TestInitInstallsThePluginWhenClaudeLacksIt(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.claude = true
	inRepo(t, w.server.URL)
	w.execOut["claude plugin list"] = "honey@greenpt\n"

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("claude plugin marketplace add ubermuda/loupe") || !w.called("claude plugin install loupe@loupe") {
		t.Errorf("exec calls = %v", w.execCalls)
	}
	if !strings.Contains(out, "Plugin: done") {
		t.Errorf("output:\n%s", out)
	}
}

func TestInitSkipsThePluginWhenClaudeHasIt(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.claude = true
	inRepo(t, w.server.URL)
	w.execOut["claude plugin list"] = "loupe@loupe 1.2.3 enabled\n"

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("claude plugin install") || !strings.Contains(out, "Plugin: skipped") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitPrintsThePluginCommandsAndGoesOnWhenInstallFails(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.claude = true
	inRepo(t, w.server.URL)
	w.execFail["claude plugin install"] = true

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("a plugin failure must not fail init: %v\n%s", err, out)
	}
	for _, want := range []string{"Plugin: failed", "claude plugin marketplace add ubermuda/loupe", "claude plugin install loupe@loupe", "Bridge: done"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestInitPrintsThePluginCommandsWhenClaudeIsMissing(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Plugin: skipped, Claude Code is not on your PATH") || !strings.Contains(out, "claude plugin install loupe@loupe") {
		t.Errorf("output:\n%s", out)
	}
}

func TestInitAddsOnlyWhatAnExistingRuleFileLacks(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	old := "# mine\nmaxWorkers: 3\n"
	if err := os.WriteFile(path, []byte(old), 0o644); err != nil {
		t.Fatal(err)
	}

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	body := readOrEmpty(t, path)
	if !strings.Contains(body, "# mine") || !strings.Contains(body, "maxWorkers: 3") {
		t.Errorf("the old content is gone:\n%s", body)
	}
	if !strings.Contains(out, "Rules: done, added") {
		t.Errorf("output:\n%s", out)
	}
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil || set.AgentsOff() != "" {
		t.Errorf("the merged file must run workers: %v, %q\n%s", err, set.AgentsOff(), body)
	}
	if info, _ := os.Stat(path); info.Mode().Perm() != 0o644 {
		t.Errorf("mode = %v, want the old 0644", info.Mode().Perm())
	}
}

func TestInitSaysProductDesignWaitsOnAMachineWithNoTerminal(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	initGOOS = "linux"

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Product design: skipped, this machine has no known terminal") {
		t.Errorf("init must say that product design waits for a person:\n%s", out)
	}

	out, err = runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("second init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Product design: skipped") {
		t.Errorf("a second run must say it again:\n%s", out)
	}
}

func TestInitSaysNothingAboutProductDesignWhenATerminalIsKnown(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if strings.Contains(out, "Product design:") {
		t.Errorf("a machine with a terminal gets the entry:\n%s", out)
	}
}

func TestInitResetWritesTheFirstRunFileAndKeepsTheProjectsMap(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	other := t.TempDir()
	old := "maxWorkers: 9\nprojects:\n  elsewhere:\n    dir: " + other + "\n"
	if err := os.WriteFile(path, []byte(old), 0o600); err != nil {
		t.Fatal(err)
	}

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--reset")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	body := readOrEmpty(t, path)
	if strings.Contains(body, "maxWorkers") {
		t.Errorf("reset kept a key it should drop:\n%s", body)
	}
	for _, want := range []string{"elsewhere:", "dir: " + other, "dev:", "dir: " + dir, "appPrompts: true"} {
		if !strings.Contains(body, want) {
			t.Errorf("reset lacks %q:\n%s", want, body)
		}
	}
	if _, err := rules.Gaps([]byte(body), dir); err != nil {
		t.Errorf("Gaps: %v", err)
	}
}

func TestInitKeepsTheAutoUpdateAnswerInAnExistingKey(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte("autoUpdate: true\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	out, err := runInitCmd(t, "", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if strings.Contains(out, "Turn on automatic updates?") || !strings.Contains(out, "Auto-update: skipped") {
		t.Errorf("init asked again:\n%s", out)
	}
}

func TestInitAnswersNoToAutoUpdateWithoutATerminal(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	path, _ := defaultRulesPath()
	if body := readOrEmpty(t, path); !strings.Contains(body, "autoUpdate: false") {
		t.Errorf("want autoUpdate: false:\n%s", body)
	}
}

func TestInitReportsABridgeThatFailsToStartAfterTheLaterSteps(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	w.bridgeErr = errors.New("the bridge stopped at start")

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err == nil || !strings.Contains(err.Error(), "the bridge stopped at start") {
		t.Fatalf("err = %v, want the bridge failure", err)
	}
	for _, want := range []string{"Bridge: failed", "loupe bridge run -d", "Playwright:", "Open " + w.server.URL} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestInitReloadsARunningBridgeWhenTheRulesChange(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte("autoUpdate: false\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	w.bridgeUp = true

	out, err := runInitCmd(t, "", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.reloads != 1 || w.bridgeStarts != 0 || !strings.Contains(out, "Bridge: done, it reloaded "+path) {
		t.Errorf("reloads = %d, starts = %d, want 1 and 0:\n%s", w.reloads, w.bridgeStarts, out)
	}

	full, err := rules.FirstRun(rules.FirstRunOptions{Slug: "dev", Dir: dir, LookPath: initLookPath, GOOS: initGOOS})
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, full, 0o600); err != nil {
		t.Fatal(err)
	}
	out, err = runInitCmd(t, "", "--no-mcp")
	if err != nil || w.reloads != 2 || !strings.Contains(out, "Rules: skipped") || !strings.Contains(out, "Auto-update: done") {
		t.Errorf("an auto-update answer alone must reload too: reloads = %d, %v\n%s", w.reloads, err, out)
	}

	w.reloadErr = errors.New("projects: dev is unknown")
	if err := os.WriteFile(path, []byte("autoUpdate: false\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	out, err = runInitCmd(t, "", "--no-mcp")
	if err == nil || !strings.Contains(err.Error(), "dev is unknown") {
		t.Fatalf("err = %v, want the reload failure", err)
	}
	for _, want := range []string{"Bridge: failed", "loupe bridge reload", "Open " + w.server.URL} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestInitStartsTheBridgeOnTheRulesFlag(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	custom := filepath.Join(t.TempDir(), "mine.yaml")

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--rules", custom)
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if body := readOrEmpty(t, custom); !strings.Contains(body, "dev:") {
		t.Errorf("custom rule file:\n%s", body)
	}
	if len(w.bridgeFlags) != 1 || w.bridgeFlags[0] != "rules="+custom {
		t.Errorf("bridge flags = %v", w.bridgeFlags)
	}
}

func writePackageJSON(t *testing.T, dir, body string) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(dir, "package.json"), []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestInitInstallsThePlaywrightBrowserWhenAsked(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	cache := t.TempDir()
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", cache)
	writePackageJSON(t, dir, `{"devDependencies":{"@playwright/test":"^1.50.0"}}`)

	out, err := runInitCmd(t, "n\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("npx playwright install chromium") || !strings.Contains(out, "Playwright: done") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitLeavesThePlaywrightBrowserWhenDeclined(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", t.TempDir())
	writePackageJSON(t, dir, `{"dependencies":{"playwright":"1"}}`)

	out, err := runInitCmd(t, "n\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("npx") || !strings.Contains(out, "run `npx playwright install chromium`") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitSkipsPlaywrightWhenChromiumIsInTheCache(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	cache := t.TempDir()
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", cache)
	if err := os.Mkdir(filepath.Join(cache, "chromium-1234"), 0o755); err != nil {
		t.Fatal(err)
	}
	writePackageJSON(t, dir, `{"devDependencies":{"@playwright/test":"1"}}`)

	out, err := runInitCmd(t, "n\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Playwright: skipped, the Chromium browser is installed") || w.called("npx") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitPrintsThePlaywrightCommandWhenTheInstallFails(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", t.TempDir())
	writePackageJSON(t, dir, `{"devDependencies":{"@playwright/test":"1"}}`)
	w.execFail["npx"] = true

	out, err := runInitCmd(t, "n\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("a Playwright failure must not fail init: %v", err)
	}
	if !strings.Contains(out, "Playwright: failed") || !strings.Contains(out, "run `npx playwright install chromium`") {
		t.Errorf("output:\n%s", out)
	}
}

func TestInitIsRegisteredAndKeepsTheLinkFlags(t *testing.T) {
	root := newRootCmd()
	cmd, _, err := root.Find([]string{"init"})
	if err != nil || cmd.Name() != "init" {
		t.Fatalf("Find init = %v, %v", cmd, err)
	}
	for _, flag := range []string{"project", "reset", "force", "mcp", "no-mcp", "mcp-json", "rules", "url"} {
		if cmd.Flags().Lookup(flag) == nil {
			t.Errorf("init lacks --%s", flag)
		}
	}
}

func TestInitChecksTheMcpServerWhenTheProjectFileExists(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatal(err)
	}
	home := t.TempDir()
	t.Setenv("CLAUDE_CONFIG_DIR", home)
	entry := `{"mcpServers":{"loupe":{"type":"stdio","command":"loupe","args":["mcp"]}}}`
	log := fakeClaude(t, "cat > "+filepath.Join(home, ".claude.json")+" <<'EOF'\n"+entry+"\nEOF")

	out, err := runInitCmd(t, "y\nn\n")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	argv, _ := os.ReadFile(log)
	if !strings.Contains(string(argv), "mcp add") || !strings.Contains(string(argv), "--scope user") {
		t.Errorf("init must declare the server, claude got %q\n%s", argv, out)
	}
	if !strings.Contains(out, "Link: skipped") || !strings.Contains(out, "Restart your agent") {
		t.Errorf("output:\n%s", out)
	}
}

func TestInitLeavesACorrectMcpServerAloneWhenTheProjectFileExists(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatal(err)
	}
	declareShim(t)
	log := fakeClaude(t, "exit 1")

	out, err := runInitCmd(t, "n\n")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Nothing to change") || !strings.Contains(out, "Link: skipped") {
		t.Errorf("output:\n%s", out)
	}
	if _, err := os.Stat(log); !os.IsNotExist(err) {
		t.Error("init ran claude though the server was correct")
	}
}

func TestInitResetKeepsTheAutoUpdateKey(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte("autoUpdate: true\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	out, err := runInitCmd(t, "", "--no-mcp", "--reset")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	body := readOrEmpty(t, path)
	if !strings.Contains(body, "autoUpdate: true") || strings.Contains(body, "autoUpdate: false") {
		t.Errorf("reset must keep autoUpdate: true:\n%s", body)
	}
	if !strings.Contains(out, "Auto-update: skipped") {
		t.Errorf("output:\n%s", out)
	}
}
