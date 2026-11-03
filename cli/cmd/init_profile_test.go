package cmd

import (
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/profile"
	"github.com/spf13/cobra"
)

// profileWorld stubs the agent and the PATH for one test, and holds a store
// with the lifecycle package.
type profileWorld struct {
	calls  [][]string
	fail   error
	onRun  func(dir string)
	agents map[string]bool
}

func newProfileWorld(t *testing.T) (*profileWorld, string) {
	t.Helper()
	t.Setenv(config.EnvVar, t.TempDir())
	w := &profileWorld{agents: map[string]bool{agentClaude: true}}
	oldAgent, oldLook, oldExe := initAgent, initLookPath, initExecutable
	t.Cleanup(func() { initAgent, initLookPath, initExecutable = oldAgent, oldLook, oldExe })
	initExecutable = func() (string, error) { return "/opt/bin/loupe", nil }
	initLookPath = func(name string) (string, error) {
		if w.agents[name] {
			return "/bin/" + name, nil
		}

		return "", os.ErrNotExist
	}
	initAgent = func(dir string, _ io.Reader, _ io.Writer, name string, args ...string) error {
		w.calls = append(w.calls, append([]string{name}, args...))
		if w.onRun != nil {
			w.onRun(dir)
		}

		return w.fail
	}
	dir := t.TempDir()

	return w, dir
}

func runProfileStep(t *testing.T, dir string, site api.Site, o initOptions) string {
	t.Helper()

	return runProfileStepOn(t, strings.NewReader(""), dir, site, o)
}

func runProfileStepOn(t *testing.T, in io.Reader, dir string, site api.Site, o initOptions) string {
	t.Helper()
	var out strings.Builder
	cmd := &cobra.Command{}
	cmd.SetOut(&out)
	cmd.SetIn(in)
	initProfile(cmd, dir, site, o)

	return out.String()
}

// putProfilePackage writes one package version to the store.
func putProfilePackage(t *testing.T, pkg, v, meta string) string {
	t.Helper()
	dir := filepath.Join(os.Getenv(config.EnvVar), "skills", pkg, v)
	writeFile(t, filepath.Join(dir, "loupe-package.json"), meta)
	writeFile(t, filepath.Join(dir, "skills", "loupe-profile", "SKILL.md"), "x")

	return dir
}

func setCLIVersion(t *testing.T, v string) {
	t.Helper()
	old := version
	version = v
	t.Cleanup(func() { version = old })
}

func yes() *bool { v := true; return &v }
func no() *bool  { v := false; return &v }

func completeProfile(t *testing.T, dir string) {
	t.Helper()
	var b strings.Builder
	for _, name := range profile.Sections {
		b.WriteString("## " + name + "\n\n1. A value.\n\n")
	}
	writeFile(t, filepath.Join(dir, profile.Path), b.String())
}

func TestInitProfileSkipsAProjectThatNeedsNone(t *testing.T) {
	w, dir := newProfileWorld(t)
	out := runProfileStep(t, dir, api.Site{ID: "p", NeedsProfile: no()}, initOptions{})
	if len(w.calls) != 0 || !strings.Contains(out, "Profile: skipped, the workflow of this project needs no profile") {
		t.Fatalf("calls %v, out %q", w.calls, out)
	}
}

func TestInitProfileSkipsACompleteFile(t *testing.T) {
	w, dir := newProfileWorld(t)
	completeProfile(t, dir)
	out := runProfileStep(t, dir, api.Site{ID: "p", NeedsProfile: yes()}, initOptions{})
	if len(w.calls) != 0 || !strings.Contains(out, "is complete") {
		t.Fatalf("calls %v, out %q", w.calls, out)
	}
}

func TestInitProfilePrintsThePromptWithNoTerminalOrNoSkills(t *testing.T) {
	w, dir := newProfileWorld(t)
	out := runProfileStep(t, dir, api.Site{ID: "p"}, initOptions{})
	if len(w.calls) != 0 {
		t.Fatalf("agent ran: %v", w.calls)
	}
	for _, want := range []string{"Profile: skipped", "Paste this prompt", "loupe-lifecycle:loupe-profile skill", "The Loupe project is p."} {
		if !strings.Contains(out, want) {
			t.Errorf("out lacks %q:\n%s", want, out)
		}
	}
}

func TestInitProfileRunsClaudeWithThePackageFolders(t *testing.T) {
	w, dir := newProfileWorld(t)
	store := os.Getenv(config.EnvVar)
	for _, pkg := range []string{"loupe", "loupe-lifecycle"} {
		for _, v := range []string{"1.2.0", "1.10.0"} {
			writeFile(t, filepath.Join(store, "skills", pkg, v, ".claude-plugin", "plugin.json"), "{}")
		}
	}
	writeFile(t, filepath.Join(store, "skills", "loupe-lifecycle", "1.10.0", "skills", "loupe-profile", "SKILL.md"), "x")
	w.onRun = func(dir string) { completeProfile(t, dir) }

	out := runProfileStep(t, dir, api.Site{ID: "p", NeedsProfile: yes()}, initOptions{agent: agentClaude})
	if len(w.calls) != 1 {
		t.Fatalf("calls = %v\n%s", w.calls, out)
	}
	call := w.calls[0]
	for _, want := range []string{"--plugin-dir", filepath.Join(store, "skills", "loupe", "1.10.0"), filepath.Join(store, "skills", "loupe-lifecycle", "1.10.0"), "--"} {
		if !slices.Contains(call, want) {
			t.Errorf("call lacks %q: %v", want, call)
		}
	}
	if call[0] != "claude" || !strings.Contains(call[len(call)-1], "loupe-profile") {
		t.Errorf("call = %v", call)
	}
	if !strings.Contains(out, "Profile: done") {
		t.Errorf("out = %s", out)
	}
}

// The folder declares no server, as after `loupe init --no-mcp`, so the agent
// has the Loupe tools only through the flag.
func TestInitProfileGivesClaudeTheLoupeServerWhenNoneIsDeclared(t *testing.T) {
	w, dir := newProfileWorld(t)
	store := os.Getenv(config.EnvVar)
	writeFile(t, filepath.Join(store, "skills", "loupe-lifecycle", "1.0.0", "skills", "loupe-profile", "SKILL.md"), "x")

	out := runProfileStep(t, dir, api.Site{ID: "p"}, initOptions{agent: agentClaude})
	if len(w.calls) != 1 {
		t.Fatalf("calls = %v\n%s", w.calls, out)
	}
	call := w.calls[0]
	at, end := slices.Index(call, "--mcp-config"), slices.Index(call, "--")
	if at < 0 || end < at+2 {
		t.Fatalf("--mcp-config must stand before --: %v", call)
	}
	var config struct {
		McpServers map[string]struct {
			Command string   `json:"command"`
			Args    []string `json:"args"`
		} `json:"mcpServers"`
	}
	if err := json.Unmarshal([]byte(call[at+1]), &config); err != nil {
		t.Fatalf("--mcp-config value %q: %v", call[at+1], err)
	}
	server := config.McpServers["loupe"]
	if server.Command != "/opt/bin/loupe" || !slices.Equal(server.Args, []string{"mcp"}) {
		t.Errorf("server = %+v", server)
	}
}

func TestInitProfilePrintsThePromptWhenItCannotFindLoupe(t *testing.T) {
	w, dir := newProfileWorld(t)
	initExecutable = func() (string, error) { return "", os.ErrNotExist }
	store := os.Getenv(config.EnvVar)
	writeFile(t, filepath.Join(store, "skills", "loupe-lifecycle", "1.0.0", "skills", "loupe-profile", "SKILL.md"), "x")

	out := runProfileStep(t, dir, api.Site{ID: "p"}, initOptions{agent: agentClaude})
	if len(w.calls) != 0 {
		t.Errorf("an agent started with no server: %v", w.calls)
	}
	if !strings.Contains(out, "Profile: failed") || !strings.Contains(out, "Paste this prompt") || strings.Contains(out, "Profile: started") {
		t.Errorf("out = %s", out)
	}
}

func TestInitProfileRunsCodexWithThePromptAlone(t *testing.T) {
	w, dir := newProfileWorld(t)
	w.agents = map[string]bool{agentCodex: true}
	store := os.Getenv(config.EnvVar)
	writeFile(t, filepath.Join(store, "skills", "loupe-lifecycle", "1.0.0", "skills", "loupe-profile", "SKILL.md"), "x")

	out := runProfileStep(t, dir, api.Site{ID: "p"}, initOptions{agent: agentCodex})
	if len(w.calls) != 1 || w.calls[0][0] != "codex" || len(w.calls[0]) != 2 {
		t.Fatalf("calls = %v\n%s", w.calls, out)
	}
	if !strings.Contains(w.calls[0][1], filepath.Join("loupe-profile", "SKILL.md")) {
		t.Errorf("prompt lacks the skill path: %s", w.calls[0][1])
	}
	if !strings.Contains(out, "Profile: open") {
		t.Errorf("an agent that left the file missing must report open: %s", out)
	}
}

func TestInitProfileReviewFlagStartsTheAgentOnACompleteFile(t *testing.T) {
	w, dir := newProfileWorld(t)
	completeProfile(t, dir)
	store := os.Getenv(config.EnvVar)
	writeFile(t, filepath.Join(store, "skills", "loupe-lifecycle", "1.0.0", "skills", "loupe-profile", "SKILL.md"), "x")

	runProfileStep(t, dir, api.Site{ID: "p"}, initOptions{agent: agentClaude, reviewProfile: true})
	if len(w.calls) != 1 || !strings.Contains(w.calls[0][len(w.calls[0])-1], "Review every value") {
		t.Fatalf("calls = %v", w.calls)
	}
}

func TestInitProfileAgentFailureFallsBackToThePrompt(t *testing.T) {
	w, dir := newProfileWorld(t)
	w.fail = os.ErrPermission
	store := os.Getenv(config.EnvVar)
	writeFile(t, filepath.Join(store, "skills", "loupe-lifecycle", "1.0.0", "skills", "loupe-profile", "SKILL.md"), "x")

	out := runProfileStep(t, dir, api.Site{ID: "p"}, initOptions{agent: agentClaude})
	if !strings.Contains(out, "Profile: failed") || !strings.Contains(out, "Paste this prompt") {
		t.Fatalf("out = %s", out)
	}
}

func TestInitProfileAgentFlagPrintsThePromptWithNoTerminal(t *testing.T) {
	devNull, err := os.Open(os.DevNull)
	if err != nil {
		t.Fatal(err)
	}
	defer devNull.Close()

	for _, agent := range []string{"", agentClaude, agentCodex} {
		w, dir := newProfileWorld(t)
		w.agents = map[string]bool{agentClaude: true, agentCodex: true}
		putProfilePackage(t, "loupe-lifecycle", "1.0.0", `{}`)

		out := runProfileStepOn(t, devNull, dir, api.Site{ID: "p"}, initOptions{agent: agent})
		if len(w.calls) != 0 {
			t.Errorf("agent %q ran with no terminal: %v", agent, w.calls)
		}
		if !strings.Contains(out, "no terminal") || !strings.Contains(out, "Paste this prompt") {
			t.Errorf("agent %q: out = %s", agent, out)
		}
	}
}

func TestInitProfileTakesTheHighestVersionThisCLICanRun(t *testing.T) {
	w, dir := newProfileWorld(t)
	setCLIVersion(t, "1.35.0")
	loupe := putProfilePackage(t, "loupe", "1.0.0", `{"minCli":"1.35.0"}`)
	putProfilePackage(t, "loupe", "2.0.0", `{"minCli":"9.0.0"}`)
	lifecycle := putProfilePackage(t, "loupe-lifecycle", "1.0.0", `{"minCli":"1.0.0","requires":{"loupe":">=1.0.0"}}`)
	putProfilePackage(t, "loupe-lifecycle", "1.1.0", `{"minCli":"1.0.0","requires":{"loupe":">=2.0.0"}}`)
	putProfilePackage(t, "loupe-lifecycle", "2.0.0", `{"minCli":"9.0.0"}`)

	out := runProfileStep(t, dir, api.Site{ID: "p"}, initOptions{agent: agentClaude})
	if len(w.calls) != 1 {
		t.Fatalf("calls = %v\n%s", w.calls, out)
	}
	call := w.calls[0]
	for _, want := range []string{loupe, lifecycle} {
		if !slices.Contains(call, want) {
			t.Errorf("call lacks %q: %v", want, call)
		}
	}
	if prompt := call[len(call)-1]; !strings.Contains(prompt, filepath.Join(lifecycle, "skills", "loupe-profile", "SKILL.md")) {
		t.Errorf("prompt names another skill path: %s", prompt)
	}
}

func TestInitProfilePrintsThePromptWhenNoVersionFits(t *testing.T) {
	for name, meta := range map[string]string{
		"minCli":             `{"minCli":"9.0.0"}`,
		"missing dependency": `{"requires":{"loupe":">=1.0.0"}}`,
		"low dependency":     `{"requires":{"loupe-core":">=5.0.0"}}`,
		"unknown form":       `{"requires":{"loupe-core":"~1.0.0"}}`,
		"cycle":              `{"requires":{"loupe-lifecycle":">=1.0.0"}}`,
	} {
		t.Run(name, func(t *testing.T) {
			w, dir := newProfileWorld(t)
			setCLIVersion(t, "1.35.0")
			putProfilePackage(t, "loupe-core", "1.0.0", `{}`)
			putProfilePackage(t, "loupe-lifecycle", "1.0.0", meta)

			out := runProfileStep(t, dir, api.Site{ID: "p", SkillPackages: []string{"loupe-lifecycle"}}, initOptions{agent: agentClaude})
			if len(w.calls) != 0 {
				t.Errorf("agent ran: %v", w.calls)
			}
			if !strings.Contains(out, "loupe update") || !strings.Contains(out, "Paste this prompt") {
				t.Errorf("out = %s", out)
			}
		})
	}
}

func TestInitProfileDevelopmentBuildMeetsEveryMinCli(t *testing.T) {
	w, dir := newProfileWorld(t)
	setCLIVersion(t, "")
	putProfilePackage(t, "loupe-lifecycle", "1.0.0", `{"minCli":"9.0.0"}`)

	runProfileStep(t, dir, api.Site{ID: "p"}, initOptions{agent: agentClaude})
	if len(w.calls) != 1 {
		t.Fatalf("calls = %v", w.calls)
	}
}

func TestVersionLess(t *testing.T) {
	if !versionLess("1.2.0", "1.10.0") || versionLess("1.10.0", "1.2.0") || versionLess("1.0.0", "1.0.0") {
		t.Fatal("versionLess compares text, not numbers")
	}
}
