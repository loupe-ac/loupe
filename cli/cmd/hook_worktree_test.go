package cmd

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/config"
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

// claim marks a worktree as made by worktree-create, as runWorktreeCreate does.
func claim(t *testing.T, project, name string) {
	t.Helper()
	if err := writeHookClaim(context.Background(), project, "", name); err != nil {
		t.Fatal(err)
	}
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
	if err := runWorktreeCreate(context.Background(), project, "", "card-7", &out, &errOut); err != nil {
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
	if err := runWorktreeCreate(context.Background(), project, "", "card-7", &out, &errOut); err != nil {
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
	if err := runWorktreeCreate(context.Background(), project, "", "card-8", &out, &bytes.Buffer{}); err != nil {
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
	if err := runWorktreeCreate(ctx, project, "", "card-1", &bytes.Buffer{}, &bytes.Buffer{}); err == nil || !strings.Contains(err.Error(), "no WorktreeCreate") {
		t.Fatalf("no hook: %v", err)
	}
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"exit 3"}]}]}}`)
	if err := runWorktreeCreate(ctx, project, "", "card-1", &bytes.Buffer{}, &bytes.Buffer{}); err == nil {
		t.Fatal("a failing hook must fail")
	}
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"true"}]}]}}`)
	if err := runWorktreeCreate(ctx, project, "", "card-1", &bytes.Buffer{}, &bytes.Buffer{}); err == nil || !strings.Contains(err.Error(), "printed no folder") {
		t.Fatalf("no path: %v", err)
	}
	for _, name := range []string{"", "../x", "-x", "a b", "/abs"} {
		if err := runWorktreeCreate(ctx, project, "", name, &bytes.Buffer{}, &bytes.Buffer{}); err == nil || !strings.Contains(err.Error(), "refusing") {
			t.Fatalf("name %q: %v", name, err)
		}
	}
	writeSettings(t, project, "settings.json", `{not json`)
	if err := runWorktreeCreate(ctx, project, "", "card-1", &bytes.Buffer{}, &bytes.Buffer{}); err == nil {
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
	claim(t, project, "card-9")

	if err := runWorktreeRemove(context.Background(), project, "", "card-9", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	stdin, _ := os.ReadFile(log + ".stdin")
	for _, part := range []string{`"hook_event_name":"WorktreeRemove"`, `"worktree_path":"` + wt + `"`} {
		if !strings.Contains(string(stdin), part) {
			t.Fatalf("payload %s lacks %s", stdin, part)
		}
	}
	// The hook leaves the git removal to its caller, so the adapter removes it.
	if _, ok, err := findWorktree(context.Background(), project, "", "card-9"); err != nil || ok {
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
	claim(t, project, "card-4")

	if err := runWorktreeRemove(context.Background(), project, "", "card-4", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, ok, err := findWorktree(context.Background(), project, "", "card-4"); err != nil || ok {
		t.Fatalf("the worktree must be gone after teardown: %v %v", ok, err)
	}
}

func TestWorktreeRemovePrunesAFolderTheHookDeleted(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	wt := filepath.Join(project, ".wt", "card-5")
	runGit(t, project, "worktree", "add", "-q", "-b", "card-5", wt)
	claim(t, project, "card-5")
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeRemove":[{"hooks":[{"type":"command","command":"rm -rf '`+wt+`'"}]}]}}`)

	if err := runWorktreeRemove(context.Background(), project, "", "card-5", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, ok, err := findWorktree(context.Background(), project, "", "card-5"); err != nil || ok {
		t.Fatalf("the stale worktree entry must be pruned: %v %v", ok, err)
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
	if err := runWorktreeRemove(ctx, project, "", "card-3", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(log + ".stdin"); err == nil {
		t.Fatal("the hook ran with no worktree")
	}
	runGit(t, project, "worktree", "add", "-q", "-b", "card-3", filepath.Join(project, ".wt", "card-3"))
	claim(t, project, "card-3")
	writeSettings(t, project, "settings.json", `{}`)
	if err := runWorktreeRemove(ctx, project, "", "card-3", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(log + ".stdin"); err == nil {
		t.Fatal("no hook is configured")
	}
	if _, ok, _ := findWorktree(ctx, project, "", "card-3"); !ok {
		t.Fatal("a project with no worktree hook keeps its worktree")
	}
}

func TestWorktreeLookupSkipsTheMainCheckout(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	branch := strings.TrimSpace(runGit(t, project, "branch", "--show-current"))
	if _, ok, err := findWorktree(context.Background(), project, "", branch); err != nil || ok {
		t.Fatalf("the main checkout must not match: %v %v", ok, err)
	}
}

func TestWorktreeCreateClaimsOnlyTheWorktreeItMade(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	t.Setenv("LOG", filepath.Join(t.TempDir(), "log"))
	script := hookScript(t, project)
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"`+script+`"}]}]}}`)
	ctx := context.Background()
	if err := runWorktreeCreate(ctx, project, "", "card-11", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if owned, err := ownsWorktree(ctx, project, filepath.Join(project, ".wt", "card-11"), "", "card-11"); err != nil || !owned {
		t.Fatalf("the hook worktree must carry a claim: %v %v", owned, err)
	}
	// A worktree that a person made is reused, and never claimed.
	mine := filepath.Join(project, "mine", "card-12")
	runGit(t, project, "worktree", "add", "-q", "-b", "card-12", mine)
	if err := runWorktreeCreate(ctx, project, "", "card-12", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if owned, err := ownsWorktree(ctx, project, mine, "", "card-12"); err != nil || owned {
		t.Fatalf("a reused worktree must not be claimed: %v %v", owned, err)
	}
}

func TestWorktreeRemoveLeavesAnUnclaimedWorktree(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	log := filepath.Join(t.TempDir(), "log")
	t.Setenv("LOG", log)
	script := hookScript(t, project)
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeRemove":[{"hooks":[{"type":"command","command":"`+script+`"}]}]}}`)
	ctx := context.Background()
	wt := filepath.Join(project, "mine", "card-13")
	runGit(t, project, "worktree", "add", "-q", "-b", "card-13", wt)

	var errOut bytes.Buffer
	if err := runWorktreeRemove(ctx, project, "", "card-13", &bytes.Buffer{}, &errOut); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(log + ".stdin"); err == nil {
		t.Fatal("the hook ran for a worktree that worktree-create did not make")
	}
	if _, ok, _ := findWorktree(ctx, project, "", "card-13"); !ok {
		t.Fatal("an unclaimed worktree must stay")
	}
	if !strings.Contains(errOut.String(), "skipping") {
		t.Fatalf("stderr = %q", errOut.String())
	}

	// A claim for another name does not cover the worktree either.
	dir, err := worktreeGitDir(ctx, wt)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, hookClaimFile), []byte(`{"name":"card-99","project":""}`+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := runWorktreeRemove(ctx, project, "", "card-13", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, ok, _ := findWorktree(ctx, project, "", "card-13"); !ok {
		t.Fatal("a worktree claimed under another name must stay")
	}
}

func TestWorktreeRemoveRunsTheHookForABridgeWorktree(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	log := filepath.Join(t.TempDir(), "log")
	t.Setenv("LOG", log)
	script := hookScript(t, project)
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeRemove":[{"hooks":[{"type":"command","command":"`+script+`"}]}]}}`)
	wt, err := config.WorktreeDir("proj", 14)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(wt), 0o700); err != nil {
		t.Fatal(err)
	}
	runGit(t, project, "worktree", "add", "-q", "-b", "card-14", wt)

	if err := runWorktreeRemove(context.Background(), project, "proj", "card-14", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(log + ".stdin"); err != nil {
		t.Fatal("the remove hook must run for a worktree of the bridge")
	}
	if _, ok, _ := findWorktree(context.Background(), project, "proj", "card-14"); ok {
		t.Fatal("the bridge worktree must be gone")
	}
}

// Two project entries can share one repository, and each bridge worktree of
// card 14 is then called card-14. Teardown of one project leaves the other.
func TestWorktreeRemoveLeavesTheBridgeWorktreeOfAnotherProject(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	log := filepath.Join(t.TempDir(), "log")
	t.Setenv("LOG", log)
	script := hookScript(t, project)
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeRemove":[{"hooks":[{"type":"command","command":"`+script+`"}]}]}}`)
	ctx := context.Background()
	add := func(slug, branch string) string {
		wt, err := config.WorktreeDir(slug, 14)
		if err != nil {
			t.Fatal(err)
		}
		if err := os.MkdirAll(filepath.Dir(wt), 0o700); err != nil {
			t.Fatal(err)
		}
		runGit(t, project, "worktree", "add", "-q", "-b", branch, wt)

		return wt
	}
	other := add("alpha", "alpha-14")
	mine := add("beta", "beta-14")

	for _, slug := range []string{"", "gamma"} {
		if err := runWorktreeRemove(ctx, project, slug, "card-14", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
			t.Fatal(err)
		}
		if _, err := os.Stat(log + ".stdin"); err == nil {
			t.Fatalf("slug %q: the hook ran for the bridge worktree of another project", slug)
		}
	}
	if err := runWorktreeRemove(ctx, project, "beta", "card-14", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(log + ".stdin"); !strings.Contains(string(got), mine) {
		t.Fatalf("the hook must get the worktree of beta: %s", got)
	}
	if _, err := os.Stat(mine); err == nil {
		t.Fatal("the bridge worktree of beta must be gone")
	}
	if _, err := os.Stat(other); err != nil {
		t.Fatal("the bridge worktree of alpha must stay")
	}
	if path, ok, _ := findWorktree(ctx, project, "alpha", "card-14"); !ok || path != other {
		t.Fatalf("alpha still owns card-14: %q %v", path, ok)
	}
}

// worktree-create never reuses the bridge worktree of another project.
func TestWorktreeCreateSkipsTheBridgeWorktreeOfAnotherProject(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	t.Setenv("LOG", filepath.Join(t.TempDir(), "log"))
	script := hookScript(t, project)
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"`+script+`"}]}]}}`)
	wt, err := config.WorktreeDir("alpha", 15)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(wt), 0o700); err != nil {
		t.Fatal(err)
	}
	runGit(t, project, "worktree", "add", "-q", "-b", "alpha-15", wt)

	var out bytes.Buffer
	if err := runWorktreeCreate(context.Background(), project, "beta", "card-15", &out, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if got := strings.TrimSpace(out.String()); got != filepath.Join(project, ".wt", "card-15") {
		t.Fatalf("worktree-create printed %q", got)
	}
}

// Two project entries can share one repository, and a hook can make their
// worktrees outside the bridge folder. The claim then names the project, so
// neither project reuses or removes the hook worktree of the other.
func TestHookWorktreeClaimsAreScopedToTheirProject(t *testing.T) {
	isolateConfig(t)
	project, _ := filepath.EvalSymlinks(t.TempDir())
	newRepo(t, project)
	log := filepath.Join(t.TempDir(), "log")
	t.Setenv("LOG", log)
	script := hookScript(t, project)
	writeSettings(t, project, "settings.json", `{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"`+script+`"}]}],"WorktreeRemove":[{"hooks":[{"type":"command","command":"`+script+`"}]}]}}`)
	ctx := context.Background()
	if err := runWorktreeCreate(ctx, project, "alpha", "card-16", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	wt := filepath.Join(project, ".wt", "card-16")
	if path, ok, err := findWorktree(ctx, project, "alpha", "card-16"); err != nil || !ok || path != wt {
		t.Fatalf("alpha must find its hook worktree: %q %v %v", path, ok, err)
	}
	for _, slug := range []string{"beta", ""} {
		if _, ok, err := findWorktree(ctx, project, slug, "card-16"); err != nil || ok {
			t.Fatalf("slug %q must not reuse the hook worktree of alpha: %v %v", slug, ok, err)
		}
		if owned, err := ownsWorktree(ctx, project, wt, slug, "card-16"); err != nil || owned {
			t.Fatalf("slug %q must not own the hook worktree of alpha: %v %v", slug, owned, err)
		}
		_ = os.Remove(log + ".stdin")
		if err := runWorktreeRemove(ctx, project, slug, "card-16", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
			t.Fatal(err)
		}
		if got, err := os.ReadFile(log + ".stdin"); err == nil && strings.Contains(string(got), "WorktreeRemove") {
			t.Fatalf("slug %q ran the remove hook for the worktree of alpha", slug)
		}
		if _, err := os.Stat(wt); err != nil {
			t.Fatalf("slug %q removed the hook worktree of alpha", slug)
		}
	}
	if err := runWorktreeRemove(ctx, project, "alpha", "card-16", &bytes.Buffer{}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(wt); err == nil {
		t.Fatal("alpha teardown must remove its hook worktree")
	}
}
