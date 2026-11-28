package cmd

import (
	"bufio"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/initview"
)

// bridgeCheckRules maps dev and other. The shared implement entry runs a script
// that neither folder holds. Only other lists review, and the top-level
// experiment covers the fix entry that each project lists itself.
const bridgeCheckRules = `appPrompts: false
accounts:
  claude:
    harness: claude-code
defaults:
  account: claude
projects:
  dev:
    dir: DEVDIR
    work:
      fix:
        prompt: Dev fix.
  other:
    dir: OTHERDIR
    work:
      fix:
        prompt: Other fix.
      review:
        prompt: Review.
work:
  fix:
    prompt: Shared fix.
  implement:
    prompt: Build.
    before:
      run: [bin/missing, '{cardNumber}']
experiments:
  fix-1:
    covers: [fix]
    baseline: a
    variants:
      - {name: a, weight: 1}
`

func bridgeCheckFile(t *testing.T, body, devDir, otherDir string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "rules.yaml")
	writeFile(t, path, strings.NewReplacer("DEVDIR", devDir, "OTHERDIR", otherDir).Replace(body))

	return path
}

func linesWith(lines []string, part string) []string {
	return slices.DeleteFunc(slices.Clone(lines), func(l string) bool { return !strings.Contains(l, part) })
}

func TestInitBridgeCheckReportsEachFactOnce(t *testing.T) {
	dir := t.TempDir()
	path := bridgeCheckFile(t, bridgeCheckRules, dir, t.TempDir())

	got := checkInitBridge(path, "dev")

	want := []string{
		"Fix the before command of the shared work entry implement in " + path + ". The command is `bin/missing {cardNumber}`, and bin/missing does not exist in " + dir + ".",
		"Add a work entry review to " + path + ". Another project lists this kind, so a request of this kind for this project is skipped.",
	}
	for _, line := range want {
		if n := len(linesWith(got.actions, line)); n != 1 {
			t.Errorf("%d lines %q in %q", n, line, got.actions)
		}
	}
	for _, fact := range []string{"bin/missing", "work entry review"} {
		if n := len(linesWith(got.actions, fact)); n != 1 {
			t.Errorf("%q is in %d lines of %q", fact, n, got.actions)
		}
	}
	warning := "Check " + path + `: project dev: work fix runs without the variants of experiment "fix-1"`
	if len(linesWith(got.actions, warning)) != 1 {
		t.Errorf("the experiment warning is lost: %q", got.actions)
	}
	if len(got.actions) != 3 || len(linesWith(got.actions, "project other")) != 0 {
		t.Errorf("actions = %q, want 3 and none of project other", got.actions)
	}
	if got.note != "" {
		t.Errorf("note = %q, want none with app prompts off", got.note)
	}
}

func TestInitBridgeCheckNotesTheKindsThatRunTheAppPrompt(t *testing.T) {
	body := strings.Replace(bridgeCheckRules, "appPrompts: false", "appPrompts: true", 1)
	body = strings.Replace(body, "      review:\n        prompt: Review.\n", "      review:\n        prompt: Review.\n      design:\n        prompt: Design.\n", 1)

	t.Run("with setup scripts", func(t *testing.T) {
		// The brackets prove that the folder name is not read as a pattern.
		dir := filepath.Join(t.TempDir(), "repo[1]")
		for _, f := range []string{"scripts/dev-setup.sh", "bin/worktrees/setup.sh", "bin/setup-db", "bin/setup/readme", "scripts/build.sh", "tools/setup.sh"} {
			writeFile(t, filepath.Join(dir, f), "")
		}

		got := checkInitBridge(bridgeCheckFile(t, body, dir, t.TempDir()), "dev")

		want := "Note: for this project, the app prompt runs with no before command for: design, review." +
			" The project folder holds these setup scripts: bin/setup-db, bin/worktrees/setup.sh, scripts/dev-setup.sh." +
			" No before command runs a script for these kinds."
		if got.note != want {
			t.Errorf("note = %q, want %q", got.note, want)
		}
		if len(linesWith(got.actions, "review")) != 0 || len(linesWith(got.actions, "design")) != 0 {
			t.Errorf("an app prompt kind is an action: %q", got.actions)
		}
		if len(got.actions) != 2 {
			t.Errorf("actions = %q, want the missing script and the experiment", got.actions)
		}
	})
	t.Run("with no setup script", func(t *testing.T) {
		got := checkInitBridge(bridgeCheckFile(t, body, t.TempDir(), t.TempDir()), "dev")

		if want := "Note: for this project, the app prompt runs with no before command for: design, review."; got.note != want {
			t.Errorf("note = %q, want %q", got.note, want)
		}
	})
}

func TestInitBridgeCheckSaysWhereEachCommandIsMissing(t *testing.T) {
	dir := t.TempDir()
	absolute := filepath.Join(t.TempDir(), "gone")
	path := bridgeCheckFile(t, `accounts:
  claude:
    harness: claude-code
defaults:
  account: claude
projects:
  dev:
    dir: DEVDIR
    work:
      plan:
        prompt: Plan.
        before:
          run: [loupe-test-no-such-program, --now]
work:
  build:
    prompt: Build.
    before:
      run: [`+absolute+`]
    reset:
      run: ['~/unchecked']
`, dir, "")

	got := checkInitBridge(path, "dev")

	want := []string{
		"Fix the before command of the shared work entry build in " + path + ". The command is `" + absolute + "`, and " + absolute + " does not exist. The project folder is " + dir + ".",
		"Fix the before command of the work entry plan of this project in " + path + ". The command is `loupe-test-no-such-program --now`, and loupe-test-no-such-program is not on your PATH. The project folder is " + dir + ".",
	}
	if !slices.Equal(got.actions, want) {
		t.Errorf("actions = %q, want %q", got.actions, want)
	}
}

func TestInitBridgeCheckAddsNothingWithoutAReadableProject(t *testing.T) {
	broken := filepath.Join(t.TempDir(), "rules.yaml")
	writeFile(t, broken, "projects: [")
	for name, path := range map[string]string{
		"no file":       filepath.Join(t.TempDir(), "none.yaml"),
		"a broken file": broken,
		"no entry":      bridgeCheckFile(t, strings.Replace(bridgeCheckRules, "  dev:\n", "  third:\n", 1), t.TempDir(), t.TempDir()),
	} {
		if got := checkInitBridge(path, "dev"); len(got.actions) != 0 || got.note != "" {
			t.Errorf("%s: got %+v, want nothing", name, got)
		}
	}
}

func TestInitNextPrintsTheBridgeCheckAndBlocksNoDiscovery(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)
	dir := t.TempDir()
	writeFile(t, filepath.Join(dir, ".gitkeep"), "")
	var out strings.Builder

	initNext(&out, "https://app.example", dir, api.Site{ID: "p1", NeedsProfile: new(bool)}, initLeft{bridge: initBridgeCheck{
		actions: []string{"Check " + home + "/rules.yaml: one thing."},
		note:    "Note: see " + home + "/repo.",
	}})

	want := "\nWhat remains:\n" +
		"1. Start discovery with `loupe discovery start` when you are ready.\n" +
		"2. Check ~/rules.yaml: one thing.\n" +
		"Note: see ~/repo.\n" +
		"Open https://app.example/projects/p1\n"
	if got := out.String(); got != want {
		t.Errorf("output = %q, want %q", got, want)
	}
}

func TestInitNextPrintsTheNoteWhenNothingRemains(t *testing.T) {
	remains := false
	var out strings.Builder

	initNext(&out, "https://app.example", t.TempDir(), api.Site{ID: "p1", DiscoveryRemains: &remains}, initLeft{bridge: initBridgeCheck{note: "Note: one."}})

	if got, want := out.String(), "\nNote: one.\nOpen https://app.example/projects/p1\n"; got != want {
		t.Errorf("output = %q, want %q", got, want)
	}
}

func TestInitReportsTheBridgeCheckOfASecondProject(t *testing.T) {
	w := newInitWorld(t, simpleSite())
	dir := inRepo(t, w.server.URL)
	w.gitClean = true
	// init maps the working directory, which can differ from dir by a symlink.
	dir, err := os.Getwd()
	if err != nil {
		t.Fatal(err)
	}
	first := t.TempDir()
	writeFile(t, filepath.Join(first, "scripts/down.sh"), "")
	path, err := defaultRulesPath()
	if err != nil {
		t.Fatal(err)
	}
	writeFile(t, path, `appPrompts: true
accounts:
  claude-code:
    harness: claude-code
defaults:
  account: claude-code
  permissions: workspace
projects:
  first:
    dir: `+first+`
work:
  teardown:
    action: command
    run: [scripts/down.sh, '{cardNumber}']
`)

	out, err := runInitCmd(t, "1\nn\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	last := out[strings.Index(out, "What remains:"):]
	want := initview.ShortPath("Fix the run command of the shared work entry teardown in " + path + ". The command is `scripts/down.sh {cardNumber}`, and scripts/down.sh does not exist in " + dir + ".")
	if strings.Count(out, "scripts/down.sh does not exist") != 1 || !strings.Contains(last, want) {
		t.Errorf("last lines lack %q once:\n%s", want, out)
	}
	if !strings.Contains(last, "Start discovery with `loupe discovery start`") || strings.Contains(last, "Run `loupe init` again") {
		t.Errorf("the bridge check changed the discovery wording:\n%s", last)
	}
}

// The GitHub App wait reads the project list again. A project that had no slug
// can have one then, and the rule file does not have it as its key.
func TestInitChecksTheKeyItWroteWhenTheAppWaitBringsASlug(t *testing.T) {
	fastWait(t)
	w := newInitWorld(t, `{"id":"`+firstProject+`","name":"Dev Project","skillPackages":["loupe"],"needsProfile":false}`)
	inRepo(t, w.server.URL)
	base := initGit
	initGit = func(dir string, args ...string) (string, error) {
		if args[0] == "remote" {
			return "git@github.com:acme/app.git", nil
		}

		return base(dir, args...)
	}
	handler := w.server.Config.Handler
	w.server.Config.Handler = http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
		if strings.Contains(r.URL.Path, "/github/") {
			rw.WriteHeader(http.StatusNotFound)

			return
		}
		handler.ServeHTTP(rw, r)
	})
	oldBrowser := initOpenBrowser
	t.Cleanup(func() { initOpenBrowser = oldBrowser })
	initOpenBrowser = func(string) error {
		w.sites = `{"id":"` + firstProject + `","slug":"dev","name":"Dev Project","skillPackages":["loupe"],"needsProfile":false,` +
			`"github":{"appInstalled":true,"repositories":[{"path":"acme/app","appInstalled":true,"rulesRead":"ok"}]}}`

		return nil
	}
	path, err := defaultRulesPath()
	if err != nil {
		t.Fatal(err)
	}
	writeFile(t, path, "autoUpdate: false\naccounts:\n  claude-code:\n    harness: claude-code\ndefaults:\n  account: claude-code\nprojects: {}\n"+
		"work:\n  teardown:\n    action: command\n    run: [scripts/down.sh]\n")

	// No second line arrives, so the wait stays open until the poll sees the install.
	reader, writer := io.Pipe()
	t.Cleanup(func() { _ = writer.Close() })
	go func() { _, _ = writer.Write([]byte("y\n")) }()

	out, err := runInitWithIn(t, bufio.NewReader(reader), "--no-mcp", "--project", firstProject)
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(readOrEmpty(t, path), firstProject+":") {
		t.Fatalf("the rule file does not key the project by its id:\n%s", readOrEmpty(t, path))
	}
	if !strings.Contains(out, "scripts/down.sh does not exist") {
		t.Errorf("the bridge check is lost after the wait:\n%s", out)
	}
}
