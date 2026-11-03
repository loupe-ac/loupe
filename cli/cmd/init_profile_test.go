package cmd

import (
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
	oldAgent, oldLook := initAgent, initLookPath
	t.Cleanup(func() { initAgent, initLookPath = oldAgent, oldLook })
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
	var out strings.Builder
	cmd := &cobra.Command{}
	cmd.SetOut(&out)
	cmd.SetIn(strings.NewReader(""))
	initProfile(cmd, dir, site, o)

	return out.String()
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

func TestVersionLess(t *testing.T) {
	if !versionLess("1.2.0", "1.10.0") || versionLess("1.10.0", "1.2.0") || versionLess("1.0.0", "1.0.0") {
		t.Fatal("versionLess compares text, not numbers")
	}
}
