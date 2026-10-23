package cmd

import (
	"bytes"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/mcpjson"
	"github.com/loupe-ac/loupe/cli/internal/profile"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
)

const firstProject = "01a0c0d9-905c-7922-a586-ccc8ce043704"
const secondProject = "01a0c0e1-1111-7922-a586-ccc8ce043705"

// projectsServer answers GET /api/projects with sites.
func projectsServer(t *testing.T, sites string) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/projects" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"sites":[%s]}`, sites)
	}))
	t.Cleanup(server.Close)

	return server
}

// inRepo runs the test in a fresh directory that stands in for a repository,
// with a login stored for baseURL.
func inRepo(t *testing.T, baseURL string) string {
	t.Helper()
	useLoginConfigHome(t)
	if baseURL != "" {
		if err := config.Save(testLogin(baseURL)); err != nil {
			t.Fatalf("save config: %v", err)
		}
	}

	// Pin Claude Code's configuration at an empty directory, so no test here
	// reads, or reports on, the real one belonging to whoever runs the suite.
	t.Setenv("CLAUDE_CONFIG_DIR", t.TempDir())
	t.Setenv("LOUPE_URL", "")

	dir := t.TempDir()
	previous, err := os.Getwd()
	if err != nil {
		t.Fatalf("getwd: %v", err)
	}
	if err := os.Chdir(dir); err != nil {
		t.Fatalf("chdir: %v", err)
	}
	t.Cleanup(func() { _ = os.Chdir(previous) })

	return dir
}

// runInit runs `loupe project link` with args and the given answer on stdin.
func runLink(t *testing.T, answer string, args ...string) (string, error) {
	t.Helper()
	var out bytes.Buffer
	cmd := newProjectLinkCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetIn(strings.NewReader(answer))
	cmd.SetArgs(args)
	err := cmd.Execute()

	return out.String(), err
}

func TestProjectLinkAsksEvenWhenTheLoginCoversOneProject(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"dev","name":"Dev Project"}`)
	dir := inRepo(t, server.URL)

	out, err := runLink(t, "1\n")
	if err != nil {
		t.Fatalf("project link: %v", err)
	}
	if !strings.Contains(out, "1) Dev Project") || !strings.Contains(out, "Choose 1 to 1") {
		t.Fatalf("output must list the project and ask, got %q", out)
	}
	if strings.Contains(out, "Create a new project") {
		t.Fatalf("project link must not offer to create a project, got %q", out)
	}

	file, err := projectfile.Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != firstProject {
		t.Fatalf("Project: got %q, want %q", file.Project, firstProject)
	}
}

func TestProjectLinkRefusesTheCreateRowNumber(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"dev","name":"Dev Project"}`)
	dir := inRepo(t, server.URL)

	if _, err := runLink(t, "2\n"); err == nil {
		t.Fatal("project link with a number past the list: got no error")
	}
	if _, err := projectfile.Load(dir); err == nil {
		t.Fatal("project link must write no file when the answer is refused")
	}
}

func TestProjectLinkWithNoTerminalListsTheProjectsAndStops(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"dev","name":"Dev Project"}`)
	dir := inRepo(t, server.URL)
	devNull, err := os.Open(os.DevNull)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = devNull.Close() })

	var out bytes.Buffer
	cmd := newProjectLinkCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetIn(devNull)
	cmd.SetArgs(nil)
	err = cmd.Execute()

	if err == nil || !strings.Contains(err.Error(), "--project <slug>") || strings.Contains(err.Error(), "--name") {
		t.Fatalf("err = %v, want a stop that names --project alone", err)
	}
	if !strings.Contains(out.String(), "Dev Project") {
		t.Errorf("output must list the project, got %q", out.String())
	}
	if _, err := projectfile.Load(dir); err == nil {
		t.Fatal("project link must write no file when nobody can answer")
	}
}

func TestProjectLinkAsksWhichProjectWhenTheLoginCoversSeveral(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"one","name":"One"},{"id":"`+secondProject+`","slug":"two","name":"Two"}`)
	dir := inRepo(t, server.URL)

	if _, err := runLink(t, "2\n"); err != nil {
		t.Fatalf("project link: %v", err)
	}

	file, err := projectfile.Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != secondProject {
		t.Fatalf("Project: got %q, want the second project", file.Project)
	}
}

func TestProjectLinkRefusesAnAnswerThatIsNotOnTheList(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"one","name":"One"},{"id":"`+secondProject+`","slug":"two","name":"Two"}`)
	dir := inRepo(t, server.URL)

	if _, err := runLink(t, "9\n"); err == nil {
		t.Fatal("project link with an answer off the list: got no error")
	}
	if _, err := projectfile.Load(dir); err == nil {
		t.Fatal("project link must write no file when the answer is refused")
	}
}

func TestProjectLinkRefusesToReplaceAnExistingFileWithoutForce(t *testing.T) {
	dir := inRepo(t, "")
	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatalf("Write: %v", err)
	}

	_, err := runLink(t, "", "--project", secondProject)
	if err == nil {
		t.Fatal("project link over an existing file: got no error")
	}
	if !strings.Contains(err.Error(), "--force") {
		t.Fatalf("error must say how to replace it, got %v", err)
	}

	file, _ := projectfile.Load(dir)
	if file.Project != firstProject {
		t.Fatalf("Project: got %q, want the file left alone", file.Project)
	}
}

func TestProjectLinkReplacesAnExistingFileWithForce(t *testing.T) {
	dir := inRepo(t, "")
	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatalf("Write: %v", err)
	}

	if _, err := runLink(t, "", "--project", secondProject, "--force"); err != nil {
		t.Fatalf("project link --force: %v", err)
	}

	file, err := projectfile.Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != secondProject {
		t.Fatalf("Project: got %q, want the replacement", file.Project)
	}
}

func TestProjectLinkWithAProjectFlagAsksTheServerNothing(t *testing.T) {
	dir := inRepo(t, "")

	if _, err := runLink(t, "", "--project", firstProject); err != nil {
		t.Fatalf("project link --project: %v", err)
	}

	file, err := projectfile.Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != firstProject {
		t.Fatalf("Project: got %q, want %q", file.Project, firstProject)
	}
}

func TestProjectLinkSaysSoWhenTheLoginCoversNoProject(t *testing.T) {
	server := projectsServer(t, "")
	inRepo(t, server.URL)

	_, err := runLink(t, "")
	if err == nil {
		t.Fatal("project link with no project: got no error")
	}
	if !strings.Contains(err.Error(), "no project") {
		t.Fatalf("error must say the login covers no project, got %v", err)
	}
}

func TestProjectLinkRefusesToReplaceAMalformedFileWithoutForce(t *testing.T) {
	dir := inRepo(t, "")
	if err := os.WriteFile(filepath.Join(dir, projectfile.Name), []byte("project: [broken\n"), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	_, err := runLink(t, "", "--project", firstProject)
	if err == nil {
		t.Fatal("project link over a malformed file: got no error")
	}
	if !strings.Contains(err.Error(), "--force") {
		t.Fatalf("error must say how to replace it, got %v", err)
	}
}

// mcpState reads what .mcp.json in dir says about the loupe server.
func mcpState(t *testing.T, dir string) mcpjson.State {
	t.Helper()
	state, _, err := mcpjson.Read(dir)
	if err != nil {
		t.Fatalf("mcpjson.Read: %v", err)
	}

	return state
}

func TestConfirmReadsTheAnswer(t *testing.T) {
	for answer, want := range map[string]bool{
		"\n":     true,
		"y\n":    true,
		"Y\n":    true,
		"yes\n":  true,
		"n\n":    false,
		"N\n":    false,
		"no\n":   false,
		"":       false,
		"what\n": false,
	} {
		var out bytes.Buffer
		if got := confirm(&out, strings.NewReader(answer), "Add it?"); got != want {
			t.Errorf("confirm(%q): got %v, want %v", answer, got, want)
		}
	}
}

// declareOther makes Claude Code start something other than `loupe mcp` for
// dir, which is the case the link has to report and offer to replace.
func declareOther(t *testing.T, dir string) {
	t.Helper()
	resolved, err := filepath.EvalSymlinks(dir)
	if err != nil {
		t.Fatalf("EvalSymlinks: %v", err)
	}
	home := t.TempDir()
	t.Setenv("CLAUDE_CONFIG_DIR", home)
	body := `{"projects":{"` + resolved + `":{"mcpServers":{"loupe":{"type":"http","url":"https://app.loupe.ac/mcp"}}}}}`
	if err := os.WriteFile(filepath.Join(home, ".claude.json"), []byte(body), 0o600); err != nil {
		t.Fatalf("write fixture: %v", err)
	}
}

// fakeClaude puts a claude command on PATH whose body is the given shell.
func fakeClaude(t *testing.T, body string) string {
	t.Helper()
	dir := t.TempDir()
	log := filepath.Join(dir, "argv.log")
	script := "#!/bin/sh\necho \"$@\" >> " + log + "\n" + body + "\n"
	if err := os.WriteFile(filepath.Join(dir, "claude"), []byte(script), 0o755); err != nil {
		t.Fatalf("write fake claude: %v", err)
	}
	t.Setenv("PATH", dir+string(os.PathListSeparator)+os.Getenv("PATH"))

	return log
}

// declareShim makes Claude Code already start `loupe mcp` for every project,
// which is the state the link must leave alone.
func declareShim(t *testing.T) {
	t.Helper()
	home := t.TempDir()
	t.Setenv("CLAUDE_CONFIG_DIR", home)
	body := `{"mcpServers":{"loupe":{"type":"stdio","command":"loupe","args":["mcp"],"env":{}}}}`
	if err := os.WriteFile(filepath.Join(home, ".claude.json"), []byte(body), 0o600); err != nil {
		t.Fatalf("write fixture: %v", err)
	}
}

func TestProjectLinkDoesNothingWhenClaudeCodeAlreadyStartsTheShim(t *testing.T) {
	inRepo(t, "")
	declareShim(t)
	log := fakeClaude(t, "exit 1")

	out, err := runLink(t, "", "--project", firstProject, "--mcp")
	if err != nil {
		t.Fatalf("project link --mcp: %v", err)
	}
	if !strings.Contains(out, "Nothing to change") {
		t.Fatalf("output must say there is nothing to do, got %q", out)
	}
	if _, err := os.Stat(log); !os.IsNotExist(err) {
		t.Fatal("project link must not run claude when the server is already correct")
	}
}

func TestProjectLinkDeclaresTheShimWhenNothingDoes(t *testing.T) {
	inRepo(t, "")
	home := t.TempDir()
	t.Setenv("CLAUDE_CONFIG_DIR", home)
	entry := `{"mcpServers":{"loupe":{"type":"stdio","command":"loupe","args":["mcp"]}}}`
	log := fakeClaude(t, "cat > "+filepath.Join(home, ".claude.json")+" <<'EOF'\n"+entry+"\nEOF")

	out, err := runLink(t, "", "--project", firstProject, "--mcp")
	if err != nil {
		t.Fatalf("project link --mcp: %v", err)
	}

	argv, err := os.ReadFile(log)
	if err != nil {
		t.Fatalf("read argv: %v", err)
	}
	if !strings.Contains(string(argv), "--scope user") {
		t.Fatalf("project link must declare it for every project, argv was %q", argv)
	}
	if !strings.Contains(out, "Restart your agent") {
		t.Fatalf("output must say to restart, got %q", out)
	}
}

// A script that passed no flag must change nothing, because nothing answers the
// prompt.
func TestProjectLinkDeclaresNothingWhenNobodyAnswers(t *testing.T) {
	inRepo(t, "")
	t.Setenv("CLAUDE_CONFIG_DIR", t.TempDir())
	log := fakeClaude(t, "exit 0")

	if _, err := runLink(t, "", "--project", firstProject); err != nil {
		t.Fatalf("project link: %v", err)
	}
	if _, err := os.Stat(log); !os.IsNotExist(err) {
		t.Fatal("an unanswered prompt must not run claude")
	}
}

// --mcp may create what is missing. Replacing a declaration somebody made by
// hand always asks, so a script can never drop it.
func TestProjectLinkNeverReplacesADeclarationWithoutAsking(t *testing.T) {
	dir := inRepo(t, "")
	declareOther(t, dir)
	log := fakeClaude(t, "exit 0")

	out, err := runLink(t, "", "--project", firstProject, "--mcp")
	if err != nil {
		t.Fatalf("project link --mcp: %v", err)
	}
	if _, err := os.Stat(log); !os.IsNotExist(err) {
		t.Fatal("--mcp must not remove an existing declaration")
	}
	if !strings.Contains(out, "claude mcp remove loupe") {
		t.Fatalf("output must name the command to remove it, got %q", out)
	}
	if strings.Contains(out, "Bearer") {
		t.Fatalf("output must carry no credential, got %q", out)
	}
}

func TestProjectLinkWritesTheRepositoryFileWhenAsked(t *testing.T) {
	dir := inRepo(t, "")

	if _, err := runLink(t, "", "--project", firstProject, "--mcp-json", "--mcp"); err != nil {
		t.Fatalf("project link --mcp-json: %v", err)
	}

	state, _, err := mcpjson.Read(dir)
	if err != nil {
		t.Fatalf("mcpjson.Read: %v", err)
	}
	if mcpjson.Current != state {
		t.Fatalf("mcp state: got %v, want Current", state)
	}
}

// The file is the lowest scope, so it can be written correctly and still do
// nothing. Saying so is the difference between a working setup and a puzzle.
func TestProjectLinkSaysTheRepositoryFileIsOutranked(t *testing.T) {
	dir := inRepo(t, "")
	declareOther(t, dir)

	out, err := runLink(t, "", "--project", firstProject, "--mcp-json", "--mcp")
	if err != nil {
		t.Fatalf("project link --mcp-json: %v", err)
	}
	if !strings.Contains(out, "no effect yet") {
		t.Fatalf("output must say the file is outranked, got %q", out)
	}
}

func TestProjectLinkLeavesEverythingAloneWhenTold(t *testing.T) {
	dir := inRepo(t, "")
	t.Setenv("CLAUDE_CONFIG_DIR", t.TempDir())
	log := fakeClaude(t, "exit 0")

	// A yes on stdin, so an ignored --no-mcp acts rather than falling through
	// to a prompt nobody answers.
	if _, err := runLink(t, "y\n", "--project", firstProject, "--no-mcp"); err != nil {
		t.Fatalf("project link --no-mcp: %v", err)
	}
	if _, err := os.Stat(log); !os.IsNotExist(err) {
		t.Fatal("--no-mcp must not run claude")
	}
	if _, err := os.Stat(filepath.Join(dir, mcpjson.Name)); !os.IsNotExist(err) {
		t.Fatalf("--no-mcp must write no %s", mcpjson.Name)
	}
}

// The message printed when somebody declines tells them to run this, so it must
// reach the offer rather than stopping at the existing project file.
func TestProjectLinkWithMcpOnAnInitialisedRepositoryReachesTheOffer(t *testing.T) {
	dir := inRepo(t, "")
	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatalf("projectfile.Write: %v", err)
	}
	declareShim(t)

	out, err := runLink(t, "", "--mcp")
	if err != nil {
		t.Fatalf("project link --mcp on a linked repository: %v", err)
	}
	if !strings.Contains(out, "Nothing to change") {
		t.Fatalf("output must reach the server check, got %q", out)
	}

	file, err := projectfile.Load(dir)
	if err != nil {
		t.Fatalf("projectfile.Load: %v", err)
	}
	if file.Project != firstProject {
		t.Fatalf("Project: got %q, want the file left alone", file.Project)
	}
}

func TestMcpAndNoMcpTogetherAreRefused(t *testing.T) {
	inRepo(t, "")

	if _, err := runLink(t, "", "--project", firstProject, "--mcp", "--no-mcp"); err == nil {
		t.Fatal("project link --mcp --no-mcp: got no error")
	}
}

func TestProjectLinkWritesTheStarterProfileFromWhatItDetects(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"dev","name":"Dev Project"}`)
	dir := inRepo(t, server.URL)
	if err := os.WriteFile(filepath.Join(dir, "package.json"), []byte(`{"scripts":{"test":"vitest"}}`), 0o644); err != nil {
		t.Fatal(err)
	}

	out, err := runLink(t, "1\n")
	if err != nil {
		t.Fatalf("project link: %v", err)
	}
	if !strings.Contains(out, "Wrote "+profile.Path) {
		t.Fatalf("output must say the profile was written, got %q", out)
	}

	body, err := os.ReadFile(filepath.Join(dir, profile.Path))
	if err != nil {
		t.Fatalf("read profile: %v", err)
	}
	if !strings.Contains(string(body), "Run `npm test` ("+profile.Marker+": package.json).") {
		t.Fatalf("profile must hold the detected gate, got:\n%s", body)
	}
}

func TestProjectLinkKeepsAProfileThatExists(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"dev","name":"Dev Project"}`)
	dir := inRepo(t, server.URL)
	path := filepath.Join(dir, profile.Path)
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte("mine\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	out, err := runLink(t, "1\n")
	if err != nil {
		t.Fatalf("project link: %v", err)
	}
	if !strings.Contains(out, profile.Path+" already exists") {
		t.Fatalf("output must say the profile is kept, got %q", out)
	}
	if body, _ := os.ReadFile(path); string(body) != "mine\n" {
		t.Fatalf("profile was replaced: %q", body)
	}
}

func TestProjectLinkWritesTheProfileWhenTheProjectFileExists(t *testing.T) {
	for _, args := range [][]string{nil, {"--mcp-json", "--mcp"}} {
		dir := inRepo(t, "")
		if err := projectfile.Write(dir, firstProject); err != nil {
			t.Fatalf("Write: %v", err)
		}

		_, err := runLink(t, "", args...)
		if nil == args && (err == nil || !strings.Contains(err.Error(), "--force")) {
			t.Fatalf("project link %v over an existing file: got %v, want the --force refusal", args, err)
		}
		if nil != args && err != nil {
			t.Fatalf("project link %v: %v", args, err)
		}
		if _, err := os.Stat(filepath.Join(dir, profile.Path)); err != nil {
			t.Fatalf("project link %v must write the profile: %v", args, err)
		}
		if file, _ := projectfile.Load(dir); file.Project != firstProject {
			t.Fatalf("project link %v replaced the project file: %q", args, file.Project)
		}
	}
}

func TestProjectLinkIsRegisteredUnderProject(t *testing.T) {
	found, _, err := newRootCmd().Find([]string{"project", "link"})
	if err != nil || found.Name() != "link" {
		t.Fatalf("Find(project link) = %v, %v", found, err)
	}
}
