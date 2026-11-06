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
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"sync/atomic"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	harn "github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/profile"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// initWorld is a fake Loupe instance and the programs init runs.
type initWorld struct {
	server  *httptest.Server
	sites   string
	created atomic.Int32
	// createBody is what the server received last on POST /api/v1/bridge/projects.
	createBody atomic.Value
	// createReply, when set, answers POST /api/v1/bridge/projects with its status and
	// body instead of a 201 for the project "made".
	createReply func(body map[string]any) (int, string)

	execCalls []string
	execDirs  []string
	// execFail names the commands whose run fails, by their first words.
	execFail map[string]bool
	// execOut gives the output of a command, by its words.
	execOut map[string]string
	claude  bool
	codex   bool
	// codexHome is CODEX_HOME, where the fake `codex mcp add` writes.
	codexHome string

	bridgeStarts int
	bridgeErr    error
	bridgeUp     bool
	bridgeFlags  []string
	reloads      int
	reloadErr    error

	// accounts is what the account check reports, and gitClean says the remote
	// holds the setup files.
	accounts []accountResult
	gitClean bool
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
		case r.URL.Path == "/api/v1/bridge/projects" && r.Method == http.MethodGet:
			fmt.Fprintf(rw, `{"sites":[%s]}`, w.sites)
		case r.URL.Path == "/api/v1/projects" && r.Method == http.MethodPost:
			w.created.Add(1)
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			w.createBody.Store(body)
			if w.createReply != nil {
				status, reply := w.createReply(body)
				rw.WriteHeader(status)
				fmt.Fprint(rw, reply)

				return
			}
			rw.WriteHeader(http.StatusCreated)
			fmt.Fprintf(rw, `{"id":%q,"slug":"made","name":"Made"}`, firstProject)
		default:
			t.Errorf("unexpected request %s %s", r.Method, r.URL.Path)
		}
	}))
	t.Cleanup(w.server.Close)

	oldLook, oldGOOS, oldExec, oldStart, oldRuns, oldReload := initLookPath, initGOOS, initExec, initStartBridge, initBridgeRuns, initReloadBridge
	oldCheck, oldGit := initCheckAccounts, initGit
	t.Cleanup(func() {
		initLookPath, initGOOS, initExec, initStartBridge, initBridgeRuns, initReloadBridge = oldLook, oldGOOS, oldExec, oldStart, oldRuns, oldReload
		initCheckAccounts, initGit = oldCheck, oldGit
	})
	initCheckAccounts = func(context.Context, *rules.Set, []api.Site) []accountResult { return w.accounts }
	initGit = func(_ string, args ...string) (string, error) {
		if !w.gitClean {
			return "?? file", nil
		}
		if args[0] == "rev-parse" {
			return "origin/main", nil
		}

		return "", nil
	}
	initGOOS = "darwin"
	initLookPath = func(name string) (string, error) {
		if name == "claude" && w.claude {
			return "/bin/claude", nil
		}
		if name == "codex" && w.codex {
			return "/bin/codex", nil
		}

		return "", errors.New("not found")
	}
	w.codexHome = t.TempDir()
	t.Setenv("CODEX_HOME", w.codexHome)
	initExec = func(_ context.Context, dir string, env []string, name string, args ...string) ([]byte, error) {
		line := strings.Join(append(slices.Clone(env), append([]string{name}, args...)...), " ")
		w.execCalls = append(w.execCalls, line)
		w.execDirs = append(w.execDirs, dir)
		out := w.execOut[line]
		for prefix := range w.execFail {
			if strings.HasPrefix(line, prefix) {
				return []byte(out), errors.New("exit status 1")
			}
		}
		switch {
		case name == "codex" && slices.Contains(args, "add"):
			home := w.codexHome
			for _, e := range env {
				if h, ok := strings.CutPrefix(e, "CODEX_HOME="); ok {
					home = h
				}
			}
			writeFile(t, filepath.Join(home, "config.toml"), "# mine\n[mcp_servers.loupe]\ncommand = \"loupe\"\nargs = [\"mcp\"]\n")
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

	return runInitWithIn(t, bufio.NewReader(strings.NewReader(answers)), args...)
}

func runInitWithIn(t *testing.T, in io.Reader, args ...string) (string, error) {
	t.Helper()
	var out bytes.Buffer
	cmd := newInitCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetIn(in)
	cmd.SetArgs(args)
	err := cmd.Execute()

	return out.String(), err
}

// writeFile writes a file and the folders it needs.
func writeFile(t *testing.T, path, body string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
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

	out, err := runInitCmd(t, "1\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	for _, want := range []string{
		"Sign in: skipped", "Project: done, using Dev Project", "Link: done", "Plugin: skipped",
		"Rules: done", "Turn on automatic updates? [y/N]", "Auto-update: done, automatic updates are on",
		"Bridge: done", "Playwright: skipped",
		"What remains:", "Commit and push .loupe.yaml", "offers to start discovery", "Open " + w.server.URL + "/projects/" + firstProject,
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
	if out, err := runInitCmd(t, "1\nn\n", "--no-mcp"); err != nil {
		t.Fatalf("first run: %v\n%s", err, out)
	}
	before := map[string]string{
		"rules":   readOrEmpty(t, path),
		"project": readOrEmpty(t, filepath.Join(dir, projectfile.Name)),
		"profile": readOrEmpty(t, filepath.Join(dir, profile.Path)),
	}

	out, err := runInitCmd(t, "1\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp", "--url", w.server.URL)
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

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp", "--url", w.server.URL+"/")
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
	if body, _ := w.createBody.Load().(map[string]any); body["remote"] != "git@github.com:acme/app.git" {
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
				fmt.Fprint(rw, `{"code":"name_taken"}`)
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
		fmt.Fprint(rw, `{"code":"name_taken"}`)
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

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("claude plugin marketplace add loupe-ac/loupe") || !w.called("claude plugin install loupe@loupe") {
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
	w.execOut["claude plugin list"] = "loupe@loupe 1.2.3 enabled\nloupe-lifecycle@loupe 0.1.0 enabled\n"

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("claude plugin install") || !strings.Contains(out, "Plugin: skipped") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitInstallsOnlyTheLifecyclePluginWhenClaudeHasTheCoreOne(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.claude = true
	inRepo(t, w.server.URL)
	w.execOut["claude plugin list"] = "loupe@loupe 1.2.3 enabled\n"

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("claude plugin install loupe-lifecycle@loupe") || w.called("claude plugin install loupe@loupe") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitPrintsThePluginCommandsAndGoesOnWhenInstallFails(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.claude = true
	inRepo(t, w.server.URL)
	w.execFail["claude plugin install"] = true

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("a plugin failure must not fail init: %v\n%s", err, out)
	}
	for _, want := range []string{"Plugin: failed", "claude plugin marketplace add loupe-ac/loupe", "claude plugin install loupe@loupe", "Bridge: done"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func simpleSite() string {
	return `{"id":"` + firstProject + `","slug":"dev","name":"Dev Project","skillPackages":["loupe"],"needsProfile":false}`
}

func lifecycleSite() string {
	return `{"id":"` + firstProject + `","slug":"dev","name":"Dev Project","skillPackages":["loupe","loupe-lifecycle"],"needsProfile":false}`
}

func TestInitInstallsTheLifecyclePluginOnlyWhenTheProjectNeedsIt(t *testing.T) {
	tests := map[string]struct {
		site      string
		listed    string
		core      bool
		lifecycle bool
		line      string
	}{
		"a Simple project gets the core plugin":         {simpleSite(), "", true, false, "Plugin: done, installed loupe@loupe."},
		"a Simple project with the core plugin is done": {simpleSite(), "loupe@loupe 1.2.3 enabled\n", false, false, "Plugin: skipped, Claude Code has the Loupe plugins"},
		"a Lifecycle project gets both":                 {lifecycleSite(), "", true, true, "Plugin: done, installed loupe@loupe and loupe-lifecycle@loupe."},
		"a project with no list gets both":              {devSite(), "", true, true, "Plugin: done, installed loupe@loupe and loupe-lifecycle@loupe."},
	}
	for name, tt := range tests {
		t.Run(name, func(t *testing.T) {
			w := newInitWorld(t, tt.site)
			w.claude = true
			inRepo(t, w.server.URL)
			w.execOut["claude plugin list"] = tt.listed

			out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
			if err != nil {
				t.Fatalf("init: %v\n%s", err, out)
			}
			if got := w.called("claude plugin install loupe@loupe"); got != tt.core {
				t.Errorf("core install = %v, calls = %v", got, w.execCalls)
			}
			if got := w.called("claude plugin install loupe-lifecycle@loupe"); got != tt.lifecycle {
				t.Errorf("lifecycle install = %v, calls = %v", got, w.execCalls)
			}
			if !strings.Contains(out, tt.line) {
				t.Errorf("output lacks %q:\n%s", tt.line, out)
			}
		})
	}
}

func TestInitNamesOnlyThePluginsTheProjectNeedsWhenClaudeIsMissing(t *testing.T) {
	w := newInitWorld(t, simpleSite())
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "claude plugin install loupe@loupe") || strings.Contains(out, "claude plugin install loupe-lifecycle@loupe") {
		t.Errorf("output:\n%s", out)
	}
}

func TestInitAsksForARestartAfterAPartialPluginInstall(t *testing.T) {
	w := newInitWorld(t, lifecycleSite())
	w.claude = true
	inRepo(t, w.server.URL)
	w.execFail["claude plugin install loupe-lifecycle@loupe"] = true

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("a plugin failure must not fail init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Plugin: failed") || !strings.Contains(out, "Restart Claude Code") {
		t.Errorf("output:\n%s", out)
	}
	if strings.Contains(out, "`claude plugin install loupe@loupe`") || !strings.Contains(out, "`claude plugin install loupe-lifecycle@loupe`") {
		t.Errorf("the commands must name only the plugin that is left:\n%s", out)
	}
}

func TestInitAsksForNoRestartWhenNoPluginInstalled(t *testing.T) {
	w := newInitWorld(t, lifecycleSite())
	w.claude = true
	inRepo(t, w.server.URL)
	w.execFail["claude plugin install"] = true

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("a plugin failure must not fail init: %v\n%s", err, out)
	}
	if strings.Contains(out, "Restart Claude Code") {
		t.Errorf("output:\n%s", out)
	}
}

func TestInitPrintsThePluginCommandsWhenClaudeIsMissing(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Product design: skipped, this machine has no known terminal") {
		t.Errorf("init must say that product design waits for a person:\n%s", out)
	}

	out, err = runInitCmd(t, "1\nn\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp", "--reset")
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

	out, err := runInitCmd(t, "1\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\n", "--no-mcp")
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
	out, err = runInitCmd(t, "1\n", "--no-mcp")
	if err != nil || w.reloads != 2 || !strings.Contains(out, "Rules: skipped") || !strings.Contains(out, "Auto-update: done") {
		t.Errorf("an auto-update answer alone must reload too: reloads = %d, %v\n%s", w.reloads, err, out)
	}

	w.reloadErr = errors.New("projects: dev is unknown")
	if err := os.WriteFile(path, []byte("autoUpdate: false\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	out, err = runInitCmd(t, "1\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp", "--rules", custom)
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

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("npx playwright install chromium") || !strings.Contains(out, "Playwright: done") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitInstallsThePlaywrightBrowserOfASubfolderPackage(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", t.TempDir())
	writePackageJSON(t, dir, `{"devDependencies":{"vitest":"1"}}`)
	e2e := filepath.Join(dir, "e2e")
	if err := os.Mkdir(e2e, 0o755); err != nil {
		t.Fatal(err)
	}
	writePackageJSON(t, e2e, `{"devDependencies":{"@playwright/test":"1"}}`)

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	i := slices.IndexFunc(w.execCalls, func(c string) bool { return strings.HasPrefix(c, "npx playwright install chromium") })
	if i < 0 || !strings.Contains(out, "Playwright: done") {
		t.Fatalf("calls = %v\n%s", w.execCalls, out)
	}
	if got, _ := filepath.EvalSymlinks(w.execDirs[i]); got != mustEvalSymlinks(t, e2e) {
		t.Errorf("npx ran in %s, want %s", w.execDirs[i], e2e)
	}
}

func TestInitNamesTheSubfolderInThePlaywrightCommand(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", t.TempDir())
	e2e := filepath.Join(dir, "e2e")
	if err := os.Mkdir(e2e, 0o755); err != nil {
		t.Fatal(err)
	}
	writePackageJSON(t, e2e, `{"dependencies":{"playwright":"1"}}`)

	out, err := runInitCmd(t, "1\nn\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("npx") || !strings.Contains(out, "run `cd e2e && npx playwright install chromium`") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func mustEvalSymlinks(t *testing.T, path string) string {
	t.Helper()
	got, err := filepath.EvalSymlinks(path)
	if err != nil {
		t.Fatal(err)
	}

	return got
}

func TestInitLeavesThePlaywrightBrowserWhenDeclined(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", t.TempDir())
	writePackageJSON(t, dir, `{"dependencies":{"playwright":"1"}}`)

	out, err := runInitCmd(t, "1\nn\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("npx") || !strings.Contains(out, "run `npx playwright install chromium`") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func writePlaywrightCore(t *testing.T, dir, revision string) string {
	t.Helper()
	core := filepath.Join(dir, "node_modules", "playwright-core")
	writeNodePackage(t, core)
	body := `{"browsers":[{"name":"chromium-headless-shell","revision":"9"},{"name":"chromium","revision":"` + revision + `"}]}`
	if err := os.WriteFile(filepath.Join(core, "browsers.json"), []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}

	return core
}

func installChromium(t *testing.T, cache, revision string) {
	t.Helper()
	folder := filepath.Join(cache, "chromium-"+revision)
	if err := os.MkdirAll(folder, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(folder, "INSTALLATION_COMPLETE"), nil, 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestInitSkipsPlaywrightWhenChromiumIsInTheCache(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	cache := t.TempDir()
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", cache)
	installChromium(t, cache, "1234")
	writePackageJSON(t, dir, `{"devDependencies":{"@playwright/test":"1"}}`)
	writePlaywrightCore(t, dir, "1234")

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Playwright: skipped, the Chromium browser is installed") || w.called("npx") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitInstallsPlaywrightWhenOnlyAnotherChromiumRevisionIsInTheCache(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	cache := t.TempDir()
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", cache)
	installChromium(t, cache, "1217")
	writePackageJSON(t, dir, `{"devDependencies":{"@playwright/test":"1"}}`)
	writePlaywrightCore(t, dir, "1223")

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("npx playwright install chromium") || !strings.Contains(out, "Playwright: done") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitInstallsPlaywrightWhenTheChromiumDownloadIsIncomplete(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	cache := t.TempDir()
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", cache)
	if err := os.Mkdir(filepath.Join(cache, "chromium-1223"), 0o755); err != nil {
		t.Fatal(err)
	}
	writePackageJSON(t, dir, `{"devDependencies":{"@playwright/test":"1"}}`)
	writePlaywrightCore(t, dir, "1223")

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("npx playwright install chromium") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitReadsAHoistedPlaywrightCoreForASubfolderPackage(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	cache := t.TempDir()
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", cache)
	installChromium(t, cache, "1223")
	e2e := filepath.Join(dir, "e2e")
	if err := os.Mkdir(e2e, 0o755); err != nil {
		t.Fatal(err)
	}
	writePackageJSON(t, e2e, `{"devDependencies":{"@playwright/test":"1"}}`)
	writePlaywrightCore(t, dir, "1223")

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Playwright: skipped, the Chromium browser is installed") || w.called("npx") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func writeNodePackage(t *testing.T, dir string) {
	t.Helper()
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	writePackageJSON(t, dir, `{}`)
}

func TestInitReadsThePlaywrightCoreNestedUnderThePlaywrightPackage(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	cache := t.TempDir()
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", cache)
	installChromium(t, cache, "1217")
	writePackageJSON(t, dir, `{"devDependencies":{"@playwright/test":"1"}}`)
	writePlaywrightCore(t, dir, "1217")
	runner := filepath.Join(dir, "node_modules", "@playwright", "test")
	writeNodePackage(t, runner)
	library := filepath.Join(runner, "node_modules", "playwright")
	writeNodePackage(t, library)
	writePlaywrightCore(t, library, "1223")

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("npx playwright install chromium") {
		t.Errorf("the hoisted core of another package hid the missing browser: calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitIgnoresATestRunnerThatThePackageDoesNotList(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	cache := t.TempDir()
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", cache)
	installChromium(t, cache, "1217")
	writePackageJSON(t, dir, `{"dependencies":{"playwright":"1"}}`)
	writePlaywrightCore(t, dir, "1223")
	runner := filepath.Join(dir, "node_modules", "@playwright", "test")
	writeNodePackage(t, runner)
	writePlaywrightCore(t, runner, "1217")

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("npx playwright install chromium") {
		t.Errorf("calls = %v\n%s", w.execCalls, out)
	}
}

func TestInitReadsTheLocalBrowsersOfPlaywrightCore(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	t.Setenv("PLAYWRIGHT_BROWSERS_PATH", "0")
	writePackageJSON(t, dir, `{"devDependencies":{"@playwright/test":"1"}}`)
	core := writePlaywrightCore(t, dir, "1223")
	installChromium(t, filepath.Join(core, ".local-browsers"), "1223")

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
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

	out, err := runInitCmd(t, "1\n", "--no-mcp", "--reset")
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

func TestInitDeclaresTheMcpServerToCodexWhenOnlyCodexIsInstalled(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execFail["codex mcp get"] = true
	inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()

	out, err := runInitCmd(t, "1\ny\nn\n")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if strings.Contains(out, "to Claude Code?") {
		t.Errorf("init offered a Claude Code declaration to a Codex account:\n%s", out)
	}
	if !w.called("codex mcp add loupe -- loupe mcp") {
		t.Errorf("init did not declare the server to Codex: %v", w.execCalls)
	}
	for _, want := range []string{"Declare `loupe mcp` to Codex?", "Codex: done", "Codex approval: done", "Codex plugins: skipped, the skill store holds no package for Codex"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if body := readOrEmpty(t, filepath.Join(w.codexHome, "config.toml")); !strings.Contains(body, "# mine\n[mcp_servers.loupe]\ndefault_tools_approval_mode = \"approve\"\n") {
		t.Errorf("config.toml lacks the approval mode, or lost its comment:\n%s", body)
	}
	if body := readOrEmpty(t, path); !strings.Contains(body, "harness: codex") {
		t.Errorf("rule file has no codex account:\n%s", body)
	}
}

// codexDeclaration is what `codex mcp get loupe --json` prints.
func codexDeclaration(enabled bool, command string) string {
	return fmt.Sprintf(`{"name":"loupe","enabled":%t,"transport":{"type":"stdio","command":%q,"args":["mcp"]}}`, enabled, command)
}

func TestInitLeavesADeclaredCodexServerAlone(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execOut["codex mcp get loupe --json"] = codexDeclaration(true, "/usr/local/bin/loupe")
	writeFile(t, filepath.Join(w.codexHome, "config.toml"), "[mcp_servers.loupe]\ndefault_tools_approval_mode = \"approve\"\n")
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("codex mcp add") {
		t.Errorf("init declared the server again: %v", w.execCalls)
	}
	if !strings.Contains(out, "Codex: skipped, Codex declares") || !strings.Contains(out, "Codex approval: skipped") {
		t.Errorf("output lacks the skip:\n%s", out)
	}
}

// The bridge reads the approval mode of the account's own home and profile,
// and the profile file wins over config.toml there.
func TestInitApprovesTheToolsInTheProfileOfTheCodexAccount(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	inRepo(t, w.server.URL)
	home := t.TempDir()
	w.execOut["CODEX_HOME="+home+" codex -p work mcp get loupe --json"] = codexDeclaration(true, "loupe")
	writeFile(t, filepath.Join(home, "config.toml"), "[mcp_servers.loupe]\ndefault_tools_approval_mode = \"approve\"\n")
	profile := filepath.Join(home, "work.config.toml")
	writeFile(t, profile, "[mcp_servers.loupe]\ndefault_tools_approval_mode = \"prompt\"\n")
	path, _ := defaultRulesPath()
	writeFile(t, path, "accounts:\n  cdx:\n    harness: codex\n    codexHome: "+home+"\n    profile: work\ndefaults:\n  account: cdx\n")

	out, err := runInitCmd(t, "1\nn\n")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Codex approval: done, Codex runs the Loupe tools without asking, as "+profile+" says") {
		t.Errorf("output lacks the approval of the profile:\n%s", out)
	}
	if body := readOrEmpty(t, profile); !strings.Contains(body, `default_tools_approval_mode = "approve"`) {
		t.Errorf("the profile still overrides the mode:\n%s", body)
	}
}

// The bridge check refuses a declaration that starts another command, so init
// must not count it as connected. Replacing it always asks, even with --mcp.
func TestInitOffersToReplaceACodexServerThatIsNotLoupeMcp(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execOut["codex mcp get loupe --json"] = codexDeclaration(true, "npx")
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\ny\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "as command npx") || !strings.Contains(out, "Replace it with `loupe mcp`?") {
		t.Errorf("output lacks the offer:\n%s", out)
	}
	if !w.called("codex mcp remove loupe") || !w.called("codex mcp add loupe -- loupe mcp") {
		t.Errorf("init did not replace the declaration: %v", w.execCalls)
	}
	if !strings.Contains(out, "Codex: done, replaced") {
		t.Errorf("output lacks the result:\n%s", out)
	}
}

func TestInitLeavesAWrongCodexServerWhenDeclined(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execOut["codex mcp get loupe --json"] = codexDeclaration(true, "npx")
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("codex mcp remove") || w.called("codex mcp add") {
		t.Errorf("init changed a declaration it was told to leave: %v", w.execCalls)
	}
	if !strings.Contains(out, "run `codex mcp remove loupe && codex mcp add loupe -- loupe mcp`") {
		t.Errorf("output lacks the command:\n%s", out)
	}
}

// The bridge check turns a disabled server off, so init offers to enable it.
// It always asks, even with --mcp, because somebody disabled it by hand.
func TestInitEnablesADisabledCodexServerInTheFileThatDisablesIt(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	inRepo(t, w.server.URL)
	home := t.TempDir()
	w.execOut["CODEX_HOME="+home+" codex -p work mcp get loupe --json"] = codexDeclaration(false, "loupe")
	writeFile(t, filepath.Join(home, "config.toml"), "[mcp_servers.loupe]\ncommand = \"loupe\"\nargs = [\"mcp\"]\n")
	profile := filepath.Join(home, "work.config.toml")
	writeFile(t, profile, "# off for now\n[mcp_servers.loupe]\nenabled = false\n")
	path, _ := defaultRulesPath()
	writeFile(t, path, "accounts:\n  cdx:\n    harness: codex\n    codexHome: "+home+"\n    profile: work\ndefaults:\n  account: cdx\n")

	out, err := runInitCmd(t, "1\ny\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "has the `loupe` MCP server disabled. Enable it?") {
		t.Errorf("output lacks the offer:\n%s", out)
	}
	if !strings.Contains(out, "Codex: done, enabled the `loupe` MCP server in "+profile) {
		t.Errorf("output lacks the result:\n%s", out)
	}
	if body := readOrEmpty(t, profile); !strings.Contains(body, "# off for now\n[mcp_servers.loupe]\nenabled = true\n") {
		t.Errorf("the profile still disables the server, or lost its comment:\n%s", body)
	}
}

func TestInitLeavesADisabledCodexServerWhenDeclined(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execOut["codex mcp get loupe --json"] = codexDeclaration(false, "loupe")
	writeFile(t, filepath.Join(w.codexHome, "config.toml"), "[mcp_servers.loupe]\nenabled = false\n")
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("codex mcp remove") || w.called("codex mcp add") {
		t.Errorf("init changed a disabled declaration: %v", w.execCalls)
	}
	if !strings.Contains(out, "is disabled; set enabled = true") {
		t.Errorf("output lacks the fix:\n%s", out)
	}
	body := readOrEmpty(t, filepath.Join(w.codexHome, "config.toml"))
	if !strings.Contains(body, "enabled = false") {
		t.Errorf("init enabled a server it was told to leave:\n%s", body)
	}
	if strings.Contains(body, "default_tools_approval_mode") || strings.Contains(out, "Codex approval") {
		t.Errorf("init approved the tools of a server that stays disabled:\n%s\n%s", out, body)
	}
}

// A failed enable leaves the server disabled, so its tools get no approval.
func TestInitApprovesNoCodexToolsWhenTheEnableFails(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execOut["codex mcp get loupe --json"] = codexDeclaration(false, "loupe")
	writeFile(t, filepath.Join(w.codexHome, "config.toml"), "[mcp_servers.loupe]\ncommand = \"loupe\"\n")
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\ny\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Codex: failed, no config file of the account sets enabled = false") {
		t.Errorf("output lacks the failure:\n%s", out)
	}
	body := readOrEmpty(t, filepath.Join(w.codexHome, "config.toml"))
	if strings.Contains(body, "default_tools_approval_mode") || strings.Contains(out, "Codex approval") {
		t.Errorf("init approved the tools of a server that stays disabled:\n%s\n%s", out, body)
	}
}

// A declaration that somebody declined to make has no table to approve, so the
// approval step stays out of it.
func TestInitApprovesNoCodexToolsWhenTheDeclarationIsDeclined(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execFail["codex mcp get"] = true
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\nn\n")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if strings.Contains(out, "Codex approval") {
		t.Errorf("init ran the approval step with no declaration:\n%s", out)
	}
	if !strings.Contains(out, "Codex plugins:") {
		t.Errorf("the plugins do not need the declaration:\n%s", out)
	}
}

func TestInitPrintsTheCodexCommandWithNoMcp(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execFail["codex mcp get"] = true
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("codex mcp add") {
		t.Errorf("init declared the server despite --no-mcp: %v", w.execCalls)
	}
	if !strings.Contains(out, "run `codex mcp add loupe -- loupe mcp`") {
		t.Errorf("output lacks the command:\n%s", out)
	}
}

func TestInitPrintsTheCodexCommandWhenTheDeclarationFails(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execFail["codex mcp"] = true
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Codex: failed") || !strings.Contains(out, "run `codex mcp add loupe -- loupe mcp`") {
		t.Errorf("output lacks the failure and the command:\n%s", out)
	}
}

func TestInitSkipsCodexWhenClaudeIsInstalled(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.claude, w.codex = true, true
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("codex") || strings.Contains(out, "Codex:") {
		t.Errorf("init ran the Codex step for a Claude Code account: %v\n%s", w.execCalls, out)
	}
}

// The rule file decides the harness, so a Codex account gets its setup also on
// a machine that has Claude Code.
func TestInitSetsUpTheCodexAccountOfTheRuleFileWhenClaudeIsInstalled(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.claude, w.codex = true, true
	w.execFail["codex mcp get"] = true
	inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	writeFile(t, path, "accounts:\n  cdx:\n    harness: codex\ndefaults:\n  account: cdx\n")

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("codex mcp get loupe --json") || !strings.Contains(out, "Codex: skipped, --no-mcp is set") {
		t.Errorf("init skipped the Codex account of the rule file: %v\n%s", w.execCalls, out)
	}
}

// The bridge checks the declaration in the home and profile of the account, so
// init checks and writes it there.
func TestInitDeclaresTheCodexServerInTheHomeAndProfileOfTheAccount(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	inRepo(t, w.server.URL)
	home := t.TempDir()
	w.execFail["CODEX_HOME="+home+" codex -p work mcp get"] = true
	path, _ := defaultRulesPath()
	writeFile(t, path, "accounts:\n  cdx:\n    harness: codex\n    codexHome: "+home+"\n    profile: work\ndefaults:\n  account: cdx\n")

	out, err := runInitCmd(t, "1\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !w.called("CODEX_HOME=" + home + " codex -p work mcp add loupe -- loupe mcp") {
		t.Errorf("init did not declare the server in the account's home and profile: %v", w.execCalls)
	}
	if w.called("codex mcp") {
		t.Errorf("init ran codex in the default home: %v", w.execCalls)
	}
	if body := readOrEmpty(t, filepath.Join(home, "config.toml")); !strings.Contains(body, `default_tools_approval_mode = "approve"`) {
		t.Errorf("the account's config.toml lacks the approval mode:\n%s", body)
	}
	if !strings.Contains(out, "Codex: done, declared `loupe mcp` in "+home+" with profile work") {
		t.Errorf("output does not name the account:\n%s", out)
	}
}

// `codex mcp add` and `remove` write only config.toml, so a server that the
// profile declares stays in force after them. Init offers to rewrite the
// profile table instead, and always asks, because somebody wrote it by hand.
func TestInitReplacesAServerThatTheProfileDeclares(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	inRepo(t, w.server.URL)
	home := t.TempDir()
	w.execOut["CODEX_HOME="+home+" codex -p work mcp get loupe --json"] = codexDeclaration(true, "npx")
	profile := filepath.Join(home, "work.config.toml")
	writeFile(t, profile, "# mine\n[mcp_servers.loupe]\ncommand = \"npx\"\nargs = [\"mcp\"]\n")
	path, _ := defaultRulesPath()
	writeFile(t, path, "accounts:\n  cdx:\n    harness: codex\n    codexHome: "+home+"\n    profile: work\ndefaults:\n  account: cdx\n")

	out, err := runInitCmd(t, "1\ny\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if w.called("CODEX_HOME="+home+" codex -p work mcp remove") || w.called("CODEX_HOME="+home+" codex -p work mcp add") {
		t.Errorf("init changed config.toml, which Codex does not start: %v", w.execCalls)
	}
	if !strings.Contains(out, "Profile work declares its own `loupe` MCP server in "+profile+". Replace it with `loupe mcp`?") {
		t.Errorf("output lacks the offer:\n%s", out)
	}
	if !strings.Contains(out, "Codex: done, replaced the `loupe` MCP server in "+profile) {
		t.Errorf("output lacks the result:\n%s", out)
	}
	body := readOrEmpty(t, profile)
	if !strings.HasPrefix(body, "# mine\n[mcp_servers.loupe]\n") || !strings.Contains(body, "command = \"loupe\"\nargs = [\"mcp\"]\n") || strings.Contains(body, "npx") {
		t.Errorf("the profile does not start `loupe mcp`, or lost its comment:\n%s", body)
	}
	if !strings.Contains(body, `default_tools_approval_mode = "approve"`) {
		t.Errorf("init did not approve the tools of the server it connected:\n%s", body)
	}
}

// The replacement keeps an enabled = false of the profile, and the bridge
// turns a disabled server off, so init offers to enable it before approval.
func TestInitEnablesAServerThatTheProfileDeclaresDisabled(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	inRepo(t, w.server.URL)
	home := t.TempDir()
	w.execOut["CODEX_HOME="+home+" codex -p work mcp get loupe --json"] = codexDeclaration(false, "npx")
	profile := filepath.Join(home, "work.config.toml")
	writeFile(t, profile, "[mcp_servers.loupe]\ncommand = \"npx\"\nenabled = false\n")
	path, _ := defaultRulesPath()
	writeFile(t, path, "accounts:\n  cdx:\n    harness: codex\n    codexHome: "+home+"\n    profile: work\ndefaults:\n  account: cdx\n")

	out, err := runInitCmd(t, "1\ny\ny\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "has the `loupe` MCP server disabled. Enable it?") {
		t.Errorf("output lacks the offer:\n%s", out)
	}
	body := readOrEmpty(t, profile)
	if !strings.Contains(body, "enabled = true") || !strings.Contains(body, `default_tools_approval_mode = "approve"`) {
		t.Errorf("the profile is not enabled and approved:\n%s", body)
	}
}

func TestInitApprovesNoToolsOfAServerThatTheProfileKeepsDisabled(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	inRepo(t, w.server.URL)
	home := t.TempDir()
	w.execOut["CODEX_HOME="+home+" codex -p work mcp get loupe --json"] = codexDeclaration(false, "npx")
	profile := filepath.Join(home, "work.config.toml")
	writeFile(t, profile, "[mcp_servers.loupe]\ncommand = \"npx\"\nenabled = false\n")
	path, _ := defaultRulesPath()
	writeFile(t, path, "accounts:\n  cdx:\n    harness: codex\n    codexHome: "+home+"\n    profile: work\ndefaults:\n  account: cdx\n")

	out, err := runInitCmd(t, "1\ny\nn\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	body := readOrEmpty(t, profile)
	if !strings.Contains(body, "enabled = false") || strings.Contains(body, "default_tools_approval_mode") || strings.Contains(out, "Codex approval") {
		t.Errorf("init approved the tools of a server that stays disabled:\n%s\n%s", out, body)
	}
}

// --no-mcp leaves the server and its approval settings alone.
func TestInitApprovesNoCodexToolsWithNoMcp(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	w.execOut["codex mcp get loupe --json"] = codexDeclaration(true, "loupe")
	writeFile(t, filepath.Join(w.codexHome, "config.toml"), "[mcp_servers.loupe]\ncommand = \"loupe\"\n")
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	body := readOrEmpty(t, filepath.Join(w.codexHome, "config.toml"))
	if strings.Contains(body, "default_tools_approval_mode") || strings.Contains(out, "Codex approval") {
		t.Errorf("init approved the tools despite --no-mcp:\n%s\n%s", out, body)
	}
}

func TestInitLeavesAServerThatTheProfileDeclaresWhenDeclined(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.codex = true
	inRepo(t, w.server.URL)
	home := t.TempDir()
	w.execOut["CODEX_HOME="+home+" codex -p work mcp get loupe --json"] = codexDeclaration(true, "npx")
	profile := filepath.Join(home, "work.config.toml")
	writeFile(t, profile, "[mcp_servers.loupe]\ncommand = \"npx\"\nargs = [\"mcp\"]\n")
	path, _ := defaultRulesPath()
	writeFile(t, path, "accounts:\n  cdx:\n    harness: codex\n    codexHome: "+home+"\n    profile: work\ndefaults:\n  account: cdx\n")

	out, err := runInitCmd(t, "1\nn\nn\n", "--mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Codex: skipped, set command = \"loupe\" and args = [\"mcp\"] under [mcp_servers.loupe] in "+profile) {
		t.Errorf("output does not name the profile file:\n%s", out)
	}
	if body := readOrEmpty(t, profile); !strings.Contains(body, "npx") {
		t.Errorf("init rewrote a profile it was told to leave:\n%s", body)
	}
	if strings.Contains(out, "Codex approval") {
		t.Errorf("init approved the tools of a server it did not connect:\n%s", out)
	}
}

const madeReply = `{"id":"` + firstProject + `","slug":"made","name":"Made","domain":"docs.example.com","allowedOrigins":["https://docs.example.com"]}`

func hasLink(t *testing.T, dir string) bool {
	t.Helper()
	_, err := os.Stat(filepath.Join(dir, projectfile.Name))

	return err == nil
}

func TestInitListsOneProjectWithACreateRowAndWaitsForAnAnswer(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	for _, want := range []string{"1) Dev Project", "2) Create a new project", "Choose 1 to 2"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if file, _ := projectfile.Load(dir); file.Project != firstProject || w.created.Load() != 0 {
		t.Errorf("project = %q, created = %d", file.Project, w.created.Load())
	}
}

func TestInitCreateRowWithEnterNamesTheProjectAfterTheRemote(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	w.execOut["git remote get-url origin"] = "git@github.com:acme/app.git\n"

	out, err := runInitCmd(t, "2\n\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	body, _ := w.createBody.Load().(map[string]any)
	if body["remote"] != "git@github.com:acme/app.git" || body["name"] != nil || len(body) != 1 {
		t.Errorf("create body = %v, want the remote alone", body)
	}
	if !strings.Contains(out, "Enter: name it after git@github.com:acme/app.git") || !strings.Contains(out, "Project: done, created Made") {
		t.Errorf("output:\n%s", out)
	}
	if file, _ := projectfile.Load(dir); file.Project != firstProject {
		t.Errorf("project = %q", file.Project)
	}
}

func TestInitCreateRowAsksAgainWhenThereIsNoRemoteToNameTheProject(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	w.execFail["git remote"] = true

	out, err := runInitCmd(t, "2\n\nMy App\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	body, _ := w.createBody.Load().(map[string]any)
	if body["name"] != "My App" || body["remote"] != nil || w.created.Load() != 1 {
		t.Errorf("create body = %v, created = %d", body, w.created.Load())
	}
	if strings.Count(out, "Project name: ") != 2 || strings.Contains(out, "Enter: name it after") {
		t.Errorf("want two prompts with no default:\n%s", out)
	}
}

func TestInitCreateRowAsksAgainWhenTheNameIsTaken(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	w.execOut["git remote get-url origin"] = "git@github.com:acme/app.git\n"
	w.createReply = func(body map[string]any) (int, string) {
		if body["name"] == "Blog" {
			return http.StatusConflict, `{"code":"name_taken","slug":"blog"}`
		}

		return http.StatusCreated, madeReply
	}

	out, err := runInitCmd(t, "2\nBlog\nBlog 2\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Its slug is blog") || !strings.Contains(out, "--project blog") {
		t.Errorf("output must name the slug:\n%s", out)
	}
	if body, _ := w.createBody.Load().(map[string]any); body["name"] != "Blog 2" || w.created.Load() != 2 {
		t.Errorf("create body = %v, created = %d", body, w.created.Load())
	}
}

func TestInitCreateRowStopsWhenTheInputEnds(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	w.execFail["git remote"] = true

	_, err := runInitCmd(t, "2\n", "--no-mcp")
	if err == nil || !strings.Contains(err.Error(), "no project name was given") {
		t.Fatalf("err = %v", err)
	}
	if hasLink(t, dir) || w.created.Load() != 0 {
		t.Errorf("init made something: created = %d", w.created.Load())
	}
}

func TestInitNameCreatesAProjectWithDomainAndOrigins(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	w.createReply = func(map[string]any) (int, string) { return http.StatusCreated, madeReply }

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--name", "Docs", "--domain", "docs.example.com",
		"--origin", "https://docs.example.com", "--origin", "https://*.preview.example.com")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	body, _ := w.createBody.Load().(map[string]any)
	origins, _ := body["origins"].([]any)
	if body["name"] != "Docs" || body["domain"] != "docs.example.com" || len(origins) != 2 || origins[1] != "https://*.preview.example.com" || body["remote"] != nil {
		t.Errorf("create body = %v", body)
	}
	if strings.Contains(out, "Which project") || strings.Contains(out, "Warning") || !strings.Contains(out, "Project: done, created Made") {
		t.Errorf("output:\n%s", out)
	}
	if file, _ := projectfile.Load(dir); file.Project != firstProject {
		t.Errorf("project = %q", file.Project)
	}
}

func TestInitNameWarnsWhenTheInstanceIgnoresDomainAndOrigins(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--name", "Docs", "--origin", "https://docs.example.com")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Warning: this Loupe instance ignored --domain or --origin") {
		t.Errorf("output lacks the warning:\n%s", out)
	}
}

func TestInitRefusedOriginCreatesNothing(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	w.createReply = func(map[string]any) (int, string) {
		return http.StatusUnprocessableEntity, `{"code":"validation_failed","errors":[{"field":"origins[0]","message":"Use https."}]}`
	}

	_, err := runInitCmd(t, "n\n", "--no-mcp", "--name", "Blog2", "--origin", "http://example.com")
	if err == nil || !strings.Contains(err.Error(), `--origin "http://example.com": Use https.`) {
		t.Fatalf("err = %v", err)
	}
	if hasLink(t, dir) {
		t.Error("init wrote the project file")
	}
}

func TestInitDomainAndOriginNeedName(t *testing.T) {
	for _, args := range [][]string{
		{"--domain", "example.com"},
		{"--domain", ""},
		{"--origin", "https://example.com"},
		{"--origin", ""},
	} {
		flag := args[0]
		w := newInitWorld(t, devSite())
		dir := inRepo(t, "")

		out, err := runInitCmd(t, "", args...)
		if err == nil || !strings.Contains(err.Error(), flag+" needs --name") {
			t.Errorf("%s: err = %v", flag, err)
		}
		if strings.Contains(out, "Sign in") || w.created.Load() != 0 || hasLink(t, dir) {
			t.Errorf("%s: init went on:\n%s", flag, out)
		}
	}
}

func TestInitNameExcludesProject(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, "")

	out, err := runInitCmd(t, "", "--name", "X", "--project", "dev")
	if err == nil || !strings.Contains(err.Error(), "name") || !strings.Contains(err.Error(), "project") {
		t.Errorf("err = %v", err)
	}
	if strings.Contains(out, "Sign in") || w.created.Load() != 0 {
		t.Errorf("init went on:\n%s", out)
	}
}

func TestInitNameNeedsAValue(t *testing.T) {
	newInitWorld(t, devSite())
	inRepo(t, "")

	if _, err := runInitCmd(t, "", "--name", "  "); err == nil || !strings.Contains(err.Error(), "--name needs a project name") {
		t.Errorf("err = %v", err)
	}
}

func TestInitNameNeedsForceWhenTheRepositoryIsLinked(t *testing.T) {
	w := newInitWorld(t, devSite()+`,{"id":"`+secondProject+`","slug":"other","name":"Other"}`)
	dir := inRepo(t, w.server.URL)
	if err := projectfile.Write(dir, secondProject); err != nil {
		t.Fatal(err)
	}

	_, err := runInitCmd(t, "n\n", "--no-mcp", "--name", "X")
	if err == nil || !strings.Contains(err.Error(), "--force") || w.created.Load() != 0 {
		t.Fatalf("err = %v, created = %d", err, w.created.Load())
	}

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--name", "X", "--force")
	if err != nil {
		t.Fatalf("init --force: %v\n%s", err, out)
	}
	if file, _ := projectfile.Load(dir); file.Project != firstProject || w.created.Load() != 1 {
		t.Errorf("project = %q, created = %d", file.Project, w.created.Load())
	}
}

func TestInitNameStopsWhenTheNameIsTaken(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	w.createReply = func(map[string]any) (int, string) {
		return http.StatusConflict, `{"code":"name_taken","slug":"blog"}`
	}

	_, err := runInitCmd(t, "n\n", "--no-mcp", "--name", "Blog")
	if err == nil || !strings.Contains(err.Error(), "--project blog") {
		t.Fatalf("err = %v", err)
	}
	if hasLink(t, dir) {
		t.Error("init wrote the project file")
	}
}

func TestInitWithNoTerminalListsTheProjectAndNamesBothWaysOn(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	devNull, err := os.Open(os.DevNull)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = devNull.Close() })

	out, err := runInitWithIn(t, devNull, "--no-mcp")
	if err == nil || !strings.Contains(err.Error(), "--project <slug>") || !strings.Contains(err.Error(), `--name "<name>"`) {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(out, "Dev Project") || hasLink(t, dir) || w.created.Load() != 0 {
		t.Errorf("created = %d\n%s", w.created.Load(), out)
	}
}

func TestIsTerminalRefusesDevNullAndAcceptsOtherReaders(t *testing.T) {
	devNull, err := os.Open(os.DevNull)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = devNull.Close() })

	if isTerminal(devNull) {
		t.Error("/dev/null is not a terminal")
	}
	if !isTerminal(strings.NewReader("")) {
		t.Error("a plain reader stands in for a terminal")
	}
}

func TestInitReportsAccountsBeforeTheProfileAndFailsForAUsedAccount(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	w.accounts = []accountResult{
		{name: "main", harness: "claude", used: true},
		{name: "broken", harness: "claude", used: true, problems: []harn.Problem{{Reason: "not logged in", Detail: "run claude login"}}},
		{name: "spare", harness: "codex", problems: []harn.Problem{{Reason: "no plugins", Detail: "run loupe init"}}},
	}

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")

	if err == nil || !strings.Contains(err.Error(), "broken: not logged in") || strings.Contains(err.Error(), "spare") {
		t.Fatalf("err = %v, want only the used account", err)
	}
	for _, want := range []string{
		"Account: main (claude): ready", "Account: broken (claude): failing: not logged in", "run claude login",
		"Account: spare (codex, unused): failing: no plugins",
		"Fix the account broken: not logged in", "Open " + w.server.URL + "/projects/" + firstProject,
	} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if strings.Index(out, "Account: main") > strings.Index(out, "Profile:") {
		t.Errorf("accounts come after the profile step:\n%s", out)
	}
	if strings.Contains(out, "Fix the account spare") {
		t.Errorf("an unused account is on the last lines:\n%s", out)
	}
}

func TestInitReportsAnAccountThatFailsForThisProject(t *testing.T) {
	cases := map[string]struct{ site, key string }{
		"a project with a slug":  {devSite(), "dev"},
		"a project with no slug": {`{"id":"` + firstProject + `","name":"Dev Project"}`, firstProject},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			w := newInitWorld(t, c.site)
			inRepo(t, w.server.URL)
			w.accounts = []accountResult{
				{name: "broken", harness: "claude", used: true, problems: []harn.Problem{{Reason: "no skills", Detail: "run loupe skills update", Project: c.key}}},
				{name: "elsewhere", harness: "claude", used: true, problems: []harn.Problem{{Reason: "no folder", Detail: "clone it", Project: "other"}}},
				{name: "spare", harness: "codex", problems: []harn.Problem{{Reason: "no plugins", Project: c.key}}},
			}

			out, err := runInitCmd(t, "1\nn\n", "--no-mcp")

			if err == nil || !strings.Contains(err.Error(), "broken: no skills") {
				t.Fatalf("err = %v, want the account that fails for this project", err)
			}
			if strings.Contains(err.Error(), "elsewhere") || strings.Contains(err.Error(), "spare") {
				t.Errorf("err = %v, names an account that does not count", err)
			}
			for _, want := range []string{
				"Account: broken (claude): failing for this project: no skills", "run loupe skills update",
				"Account: elsewhere (claude): failing for project other: no folder", "clone it",
				"Account: spare (codex, unused): failing for this project: no plugins",
				"Fix the account broken: no skills",
			} {
				if !strings.Contains(out, want) {
					t.Errorf("output lacks %q:\n%s", want, out)
				}
			}
			for _, unwanted := range []string{"Account: elsewhere (claude): ready", "Fix the account elsewhere", "Fix the account spare"} {
				if strings.Contains(out, unwanted) {
					t.Errorf("output holds %q:\n%s", unwanted, out)
				}
			}
		})
	}
}

// A failure in another project shows in the report, and it asks nothing of this
// run of init.
func TestInitExitsWithSuccessWhenAnAccountFailsOnlyForAnotherProject(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	w.accounts = []accountResult{
		{name: "elsewhere", harness: "claude", used: true, problems: []harn.Problem{{Reason: "no folder", Detail: "clone it", Project: "other"}}},
	}

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("err = %v, want success", err)
	}
	if !strings.Contains(out, "Account: elsewhere (claude): failing for project other: no folder\n         clone it\n") {
		t.Errorf("output lacks the failure of the other project:\n%s", out)
	}
	if strings.Contains(out, "Fix the account") {
		t.Errorf("the last lines ask for a fix of another project:\n%s", out)
	}
}

// reportWorld is a rule file with a Simple and a Lifecycle project, and a real
// check of a Codex home that holds only the loupe plugin.
func reportWorld(t *testing.T) string {
	t.Helper()
	inRepo(t, "")
	t.Setenv("HOME", t.TempDir())
	store, err := config.Dir()
	if err != nil {
		t.Fatal(err)
	}
	putPackage(t, store, "loupe", "1.0.0")
	putPackage(t, store, "loupe-lifecycle", "0.3.0")
	bin := t.TempDir()
	programs := map[string]string{"loupe": "#!/bin/sh\nexit 0\n", "codex": `#!/bin/sh
case "$*" in
"login status") exit 0 ;;
"plugin list --json") echo '` + installedList(installedPlugin("loupe", "1.0.0")) + `'; exit 0 ;;
"mcp get loupe --json") echo '{"enabled":true,"transport":{"type":"stdio","command":"loupe","args":["mcp"]}}'; exit 0 ;;
esac
exit 2
`}
	for name, script := range programs {
		if err := os.WriteFile(filepath.Join(bin, name), []byte(script), 0o700); err != nil {
			t.Fatal(err)
		}
	}
	t.Setenv("PATH", bin+string(os.PathListSeparator)+os.Getenv("PATH"))
	home := t.TempDir()
	writeFile(t, filepath.Join(home, "config.toml"), "[mcp_servers.loupe]\ndefault_tools_approval_mode = \"approve\"\n")
	path := filepath.Join(t.TempDir(), "rules.yaml")
	writeFile(t, path, "accounts:\n  cdx:\n    harness: codex\n    codexHome: "+home+"\ndefaults:\n  account: cdx\n"+
		"projects:\n  simple:\n    dir: "+t.TempDir()+"\n  flow:\n    dir: "+t.TempDir()+"\nwork:\n  plan:\n    prompt: go\n")

	return path
}

// Each project is checked against its own packages, so an account with only the
// core plugin is ready for a Simple project and fails for a Lifecycle one.
func TestReportAccountsChecksEachProjectAgainstItsOwnPackages(t *testing.T) {
	path := reportWorld(t)
	simple := api.Site{ID: "p1", Slug: "simple", SkillPackages: []string{"loupe"}}
	flow := api.Site{ID: "p2", Slug: "flow", SkillPackages: []string{"loupe", "loupe-lifecycle"}}
	list := []api.Site{simple, flow}

	var out strings.Builder
	if failing, unchecked := reportAccounts(context.Background(), &out, path, simple, list); len(failing) != 0 || unchecked != "" {
		t.Errorf("failing for the Simple project = %v\n%s", failing, out.String())
	}
	if want := "failing for project flow: loupe plugin loupe-lifecycle is not installed"; !strings.Contains(out.String(), want) {
		t.Errorf("output lacks %q:\n%s", want, out.String())
	}
	if strings.Contains(out.String(), "failing for this project") {
		t.Errorf("the Simple project fails:\n%s", out.String())
	}

	out.Reset()
	failing, _ := reportAccounts(context.Background(), &out, path, flow, list)
	if len(failing) != 1 || !strings.Contains(failing[0], "loupe-lifecycle is not installed") {
		t.Errorf("failing for the Lifecycle project = %v\n%s", failing, out.String())
	}
	if strings.Contains(out.String(), "failing for project simple") {
		t.Errorf("the report blames the Simple project:\n%s", out.String())
	}
}

// A project that the list does not hold yet keeps the values init read for it.
func TestReportAccountsKeepsAProjectTheListLacks(t *testing.T) {
	path := reportWorld(t)
	simple := api.Site{ID: "p1", Slug: "simple", SkillPackages: []string{"loupe"}}
	old := initCheckAccounts
	t.Cleanup(func() { initCheckAccounts = old })
	var got []api.Site
	initCheckAccounts = func(_ context.Context, _ *rules.Set, sites []api.Site) []accountResult {
		got = sites

		return nil
	}

	reportAccounts(context.Background(), io.Discard, path, simple, []api.Site{{ID: "p2", Slug: "flow"}})

	if len(got) != 2 || got[0].ID != "p2" || got[1].ID != "p1" {
		t.Errorf("sites = %+v", got)
	}
}

// An account can fail in more than one way, and each problem keeps its own fix.
func TestPrintAccountNamesEachProblemInAFixedOrder(t *testing.T) {
	var out bytes.Buffer

	printAccount(&out, accountResult{name: "main", harness: "claude", used: true, problems: []harn.Problem{
		{Reason: "no folder", Detail: "clone z", Project: "z"},
		{Reason: "no folder", Detail: "clone a", Project: "a"},
		{Reason: "no skills", Detail: "run update", Project: "dev"},
		{Reason: "not logged in", Detail: "run login"},
	}}, "dev")

	want := `Account: main (claude): failing: not logged in
         run login
Account: main (claude): failing for this project: no skills
         run update
Account: main (claude): failing for project a: no folder
         clone a
Account: main (claude): failing for project z: no folder
         clone z
`
	if out.String() != want {
		t.Errorf("output = %q, want %q", out.String(), want)
	}
}

// A worker reads only the upstream tree, so a setup file counts as pushed only
// when that tree holds the same content.
func TestFilesNotPushedReadsTheUpstreamTree(t *testing.T) {
	t.Setenv("GIT_CONFIG_GLOBAL", os.DevNull)
	t.Setenv("GIT_CONFIG_SYSTEM", os.DevNull)
	const yaml, lifecycle = projectfile.Name, profile.Path
	both := []string{yaml, lifecycle}

	for _, tc := range []struct {
		name  string
		setup func(git func(...string), write func(file, text string))
		want  []string
	}{
		{"both files pushed", func(git func(...string), write func(string, string)) {
			write(yaml, "a")
			write(lifecycle, "b")
			git("add", yaml, lifecycle)
			git("commit", "-m", "setup")
			git("push")
		}, nil},
		{"both files ignored", func(_ func(...string), write func(string, string)) {
			write(".gitignore", ".loupe.yaml\n.loupe/\n")
			write(yaml, "a")
			write(lifecycle, "b")
		}, both},
		{"one file ignored", func(git func(...string), write func(string, string)) {
			write(".git/info/exclude", ".loupe/\n")
			write(yaml, "a")
			write(lifecycle, "b")
			git("add", yaml)
			git("commit", "-m", "setup")
			git("push")
		}, []string{lifecycle}},
		{"files ignored after they were pushed", func(git func(...string), write func(string, string)) {
			write(yaml, "a")
			write(lifecycle, "b")
			git("add", yaml, lifecycle)
			git("commit", "-m", "setup")
			git("push")
			write(".git/info/exclude", ".loupe.yaml\n.loupe/\n")
		}, nil},
		{"files committed and not pushed", func(git func(...string), write func(string, string)) {
			write(yaml, "a")
			write(lifecycle, "b")
			git("add", yaml, lifecycle)
			git("commit", "-m", "setup")
		}, both},
		{"a file edited after the push", func(git func(...string), write func(string, string)) {
			write(yaml, "a")
			git("add", yaml)
			git("commit", "-m", "setup")
			git("push")
			write(yaml, "changed")
		}, []string{yaml}},
		{"no upstream", func(git func(...string), write func(string, string)) {
			write(yaml, "a")
			git("add", yaml)
			git("commit", "-m", "setup")
			git("branch", "--unset-upstream")
		}, []string{yaml}},
		{"no setup file", func(func(...string), func(string, string)) {}, nil},
	} {
		t.Run(tc.name, func(t *testing.T) {
			root := t.TempDir()
			dir := filepath.Join(root, "work")
			run := func(cwd string, args ...string) {
				t.Helper()
				c := exec.Command("git", append([]string{"-c", "user.name=t", "-c", "user.email=t@example.test"}, args...)...)
				c.Dir = cwd
				if out, err := c.CombinedOutput(); err != nil {
					t.Fatalf("git %v: %v\n%s", args, err, out)
				}
			}
			run(root, "init", "--bare", "-b", "main", "origin.git")
			run(root, "init", "-b", "main", "work")
			run(dir, "remote", "add", "origin", filepath.Join(root, "origin.git"))
			run(dir, "commit", "--allow-empty", "-m", "start")
			run(dir, "push", "-u", "origin", "main")

			tc.setup(func(args ...string) { t.Helper(); run(dir, args...) }, func(file, text string) {
				t.Helper()
				path := filepath.Join(dir, file)
				if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
					t.Fatal(err)
				}
				if err := os.WriteFile(path, []byte(text), 0o644); err != nil {
					t.Fatal(err)
				}
			})

			if got := filesNotPushed(dir, api.Site{}); !slices.Equal(got, tc.want) {
				t.Errorf("filesNotPushed = %v, want %v", got, tc.want)
			}
		})
	}
}

func TestInitLastLinesNameOnlyWhatRemains(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	w.accounts = []accountResult{{name: "main", harness: "claude", used: true}}
	w.gitClean = true

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	last := out[strings.Index(out, "What remains:"):]
	if strings.Contains(last, "Commit and push") || strings.Contains(last, "Fix the account") || strings.Contains(last, "Restart Claude Code") {
		t.Errorf("last lines name a step that is done:\n%s", last)
	}
	if !strings.Contains(last, "1. Finish the profile") {
		t.Errorf("last lines lack the profile step:\n%s", last)
	}
}

func TestInitLastLinesNameDiscoveryOnlyWhileItRemains(t *testing.T) {
	site := func(remains string) string {
		return `{"id":"` + firstProject + `","slug":"dev","name":"Dev Project"` + remains + `}`
	}
	for _, tc := range []struct {
		name, site string
		want       bool
	}{
		{"an older app sends no value", site(""), true},
		{"no run started", site(`,"discoveryRemains":true`), true},
		{"a run started", site(`,"discoveryRemains":false`), false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			w := newInitWorld(t, tc.site)
			inRepo(t, w.server.URL)
			w.gitClean = true

			out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
			if err != nil {
				t.Fatalf("init: %v\n%s", err, out)
			}

			if got := strings.Contains(out, "offers to start discovery"); got != tc.want {
				t.Errorf("names discovery = %v, want %v:\n%s", got, tc.want, out)
			}
			if !strings.Contains(out, "Open "+w.server.URL+"/projects/"+firstProject) {
				t.Errorf("output lacks the link:\n%s", out)
			}
		})
	}
}

func TestInitNextPrintsNoHeadingWhenNothingRemains(t *testing.T) {
	remains := false
	var out strings.Builder

	initNext(&out, "https://app.example", t.TempDir(), api.Site{ID: "p1", DiscoveryRemains: &remains}, initLeft{})

	if got, want := out.String(), "\nOpen https://app.example/projects/p1\n"; got != want {
		t.Errorf("output = %q, want %q", got, want)
	}
}

func TestInitRestartLineFollowsAPluginInstall(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.claude = true
	inRepo(t, w.server.URL)
	w.gitClean = true

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out[strings.Index(out, "What remains:"):], "Restart Claude Code") {
		t.Errorf("no restart line:\n%s", out)
	}
}
