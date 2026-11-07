package cmd

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func writeSettings(t *testing.T, project, name, content string) {
	t.Helper()
	dir := filepath.Join(project, ".claude")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
}

// hookScript writes a script that records its stdin and environment, makes a
// worktree for a create event, and prints its path after a banner line.
func hookScript(t *testing.T, project string) string {
	t.Helper()
	script := filepath.Join(t.TempDir(), "hook.sh")
	body := `#!/bin/sh
cat > "$LOG.stdin"
echo "$CLAUDE_PROJECT_DIR" > "$LOG.env"
case "$(cat "$LOG.stdin")" in
*WorktreeCreate*)
  name=$(sed 's/.*"name":"\([^"]*\)".*/\1/' "$LOG.stdin")
  git worktree add -q -b "$name" "$CLAUDE_PROJECT_DIR/.wt/$name" >&2
  echo banner
  echo "$CLAUDE_PROJECT_DIR/.wt/$name"
  ;;
esac
`
	if err := os.WriteFile(script, []byte(body), 0o700); err != nil {
		t.Fatal(err)
	}

	return script
}

func TestWorktreeCreateRunsTheHookAndPrintsTheLastLine(t *testing.T) {
	isolateConfig(t)
	project, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	newRepo(t, project)
	log := filepath.Join(t.TempDir(), "log")
	t.Setenv("LOG", log)
	script := hookScript(t, project)
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"`+script+`","timeout":30}]}]}}`)

	var out, errOut bytes.Buffer
	if err := runWorktreeCreate(context.Background(), project, "card-7", &out, &errOut); err != nil {
		t.Fatal(err)
	}
	want := filepath.Join(project, ".wt", "card-7")
	if strings.TrimSpace(out.String()) != want {
		t.Fatalf("stdout = %q, want %q", out.String(), want)
	}
	if !strings.Contains(errOut.String(), "banner") {
		t.Fatalf("the other stdout lines go to stderr, got %q", errOut.String())
	}
	stdin, _ := os.ReadFile(log + ".stdin")
	for _, part := range []string{`"hook_event_name":"WorktreeCreate"`, `"name":"card-7"`, `"cwd":"` + project + `"`} {
		if !strings.Contains(string(stdin), part) {
			t.Fatalf("payload %s lacks %s", stdin, part)
		}
	}
	env, _ := os.ReadFile(log + ".env")
	if strings.TrimSpace(string(env)) != project {
		t.Fatalf("CLAUDE_PROJECT_DIR = %q", env)
	}

	// A second call finds the worktree and skips the hook.
	if err := os.Remove(log + ".stdin"); err != nil {
		t.Fatal(err)
	}
	out.Reset()
	if err := runWorktreeCreate(context.Background(), project, "card-7", &out, &errOut); err != nil {
		t.Fatal(err)
	}
	if strings.TrimSpace(out.String()) != want {
		t.Fatalf("second stdout = %q", out.String())
	}
	if _, err := os.Stat(log + ".stdin"); err == nil {
		t.Fatal("the hook ran again for an existing worktree")
	}
}

func TestWorktreeCreateReadsTheLocalSettingsToo(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	t.Setenv("LOG", filepath.Join(t.TempDir(), "log"))
	script := hookScript(t, project)
	writeSettings(t, project, "settings.json", `{"permissions":{}}`)
	writeSettings(t, project, "settings.local.json", `{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"`+script+`"}]}]}}`)

	var out bytes.Buffer
	if err := runWorktreeCreate(context.Background(), project, "card-8", &out, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if !strings.HasSuffix(strings.TrimSpace(out.String()), "card-8") {
		t.Fatalf("stdout = %q", out.String())
	}
}

func TestWorktreeCreateFailures(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	ctx := context.Background()
	if err := runWorktreeCreate(ctx, project, "card-1", &bytes.Buffer{}, &bytes.Buffer{}); err == nil || !strings.Contains(err.Error(), "no WorktreeCreate") {
		t.Fatalf("no hook: %v", err)
	}
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"exit 3"}]}]}}`)
	if err := runWorktreeCreate(ctx, project, "card-1", &bytes.Buffer{}, &bytes.Buffer{}); err == nil {
		t.Fatal("a failing hook must fail")
	}
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"true"}]}]}}`)
	if err := runWorktreeCreate(ctx, project, "card-1", &bytes.Buffer{}, &bytes.Buffer{}); err == nil || !strings.Contains(err.Error(), "printed no folder") {
		t.Fatalf("no path: %v", err)
	}
	for _, name := range []string{"", "../x", "-x", "a b", "/abs"} {
		if err := runWorktreeCreate(ctx, project, name, &bytes.Buffer{}, &bytes.Buffer{}); err == nil || !strings.Contains(err.Error(), "refusing") {
			t.Fatalf("name %q: %v", name, err)
		}
	}
	writeSettings(t, project, "settings.json", `{not json`)
	if err := runWorktreeCreate(ctx, project, "card-1", &bytes.Buffer{}, &bytes.Buffer{}); err == nil {
		t.Fatal("a broken settings file must fail")
	}
}

func TestWorktreeRemoveSendsTheWorktreePath(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	log := filepath.Join(t.TempDir(), "log")
	t.Setenv("LOG", log)
	script := hookScript(t, project)
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeRemove":[{"hooks":[{"type":"command","command":"`+script+`"}]}]}}`)
	wt := filepath.Join(project, ".wt", "card-9")
	runGit(t, project, "worktree", "add", "-q", "-b", "card-9", wt)

	if err := runWorktreeRemove(context.Background(), project, "card-9", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	stdin, _ := os.ReadFile(log + ".stdin")
	for _, part := range []string{`"hook_event_name":"WorktreeRemove"`, `"worktree_path":"` + wt + `"`} {
		if !strings.Contains(string(stdin), part) {
			t.Fatalf("payload %s lacks %s", stdin, part)
		}
	}
	// The hook leaves the git removal to its caller, so the adapter removes it.
	if _, ok, err := findWorktree(context.Background(), project, "card-9"); err != nil || ok {
		t.Fatalf("the worktree must be gone after teardown: %v %v", ok, err)
	}
	if _, err := os.Stat(wt); err == nil {
		t.Fatal("the worktree folder must be gone after teardown")
	}
}

func TestWorktreeRemoveRemovesAHookWorktreeWithNoRemoveHook(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"true"}]}]}}`)
	wt := filepath.Join(project, ".wt", "card-4")
	runGit(t, project, "worktree", "add", "-q", "-b", "card-4", wt)

	if err := runWorktreeRemove(context.Background(), project, "card-4", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, ok, err := findWorktree(context.Background(), project, "card-4"); err != nil || ok {
		t.Fatalf("the worktree must be gone after teardown: %v %v", ok, err)
	}
}

func TestWorktreeRemoveDoesNothingWithoutAWorktreeOrAHook(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	log := filepath.Join(t.TempDir(), "log")
	t.Setenv("LOG", log)
	script := hookScript(t, project)
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeRemove":[{"hooks":[{"type":"command","command":"`+script+`"}]}]}}`)
	ctx := context.Background()
	if err := runWorktreeRemove(ctx, project, "card-3", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(log + ".stdin"); err == nil {
		t.Fatal("the hook ran with no worktree")
	}
	runGit(t, project, "worktree", "add", "-q", "-b", "card-3", filepath.Join(project, ".wt", "card-3"))
	writeSettings(t, project, "settings.json", `{}`)
	if err := runWorktreeRemove(ctx, project, "card-3", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(log + ".stdin"); err == nil {
		t.Fatal("no hook is configured")
	}
	if _, ok, _ := findWorktree(ctx, project, "card-3"); !ok {
		t.Fatal("a project with no worktree hook keeps its worktree")
	}
}

func TestWorktreeLookupSkipsTheMainCheckout(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	branch := strings.TrimSpace(runGit(t, project, "branch", "--show-current"))
	if _, ok, err := findWorktree(context.Background(), project, branch); err != nil || ok {
		t.Fatalf("the main checkout must not match: %v %v", ok, err)
	}
}
