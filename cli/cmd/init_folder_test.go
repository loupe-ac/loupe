package cmd

import (
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
	"github.com/spf13/cobra"
)

// inSubfolder puts the fake git at a repository root that differs from the
// working folder. It returns the working folder and the root.
func inSubfolder(t *testing.T, w *initWorld) (sub, root string) {
	t.Helper()
	sub = inRepo(t, w.server.URL)
	root = t.TempDir()
	w.execOut["git rev-parse --show-prefix"] = "terraform/\n"
	w.execOut["git rev-parse --show-toplevel"] = root + "\n"

	return sub, root
}

func noTerminal(t *testing.T) *os.File {
	t.Helper()
	devNull, err := os.Open(os.DevNull)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = devNull.Close() })

	return devNull
}

func TestInitFolderRootSetsUpTheRootAndLeavesTheSubfolderEmpty(t *testing.T) {
	w := newInitWorld(t, devSite())
	sub, root := inSubfolder(t, w)

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--project", "dev", "--folder", "root")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Folder: done, set up the repository root "+root) {
		t.Errorf("output:\n%s", out)
	}
	if !hasLink(t, root) || hasLink(t, sub) {
		t.Errorf("project file at root = %v, in the subfolder = %v", hasLink(t, root), hasLink(t, sub))
	}
	if _, err := os.Stat(filepath.Join(sub, ".loupe")); err == nil {
		t.Error("init made .loupe in the subfolder")
	}
	path, _ := defaultRulesPath()
	if body := readOrEmpty(t, path); !strings.Contains(body, "dir: "+root) {
		t.Errorf("rule file lacks dir: %s:\n%s", root, body)
	}
	for i, dir := range w.execDirs {
		if dir == sub && !strings.HasPrefix(w.execCalls[i], "git rev-parse") {
			t.Errorf("command %q ran in the subfolder", w.execCalls[i])
		}
	}
}

func TestInitFolderCurrentSetsUpTheSubfolderAndWarns(t *testing.T) {
	w := newInitWorld(t, devSite())
	sub, root := inSubfolder(t, w)

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--project", "dev", "--folder", "current")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	for _, want := range []string{"Folder: done, set up " + sub + ", below the repository root " + root, "Warning: a bridge worker starts at the repository root"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if !hasLink(t, sub) || hasLink(t, root) {
		t.Errorf("project file in the subfolder = %v, at root = %v", hasLink(t, sub), hasLink(t, root))
	}
}

func TestInitFolderRefusesAValueThatIsNeitherChoice(t *testing.T) {
	w := newInitWorld(t, devSite())
	inSubfolder(t, w)

	_, err := runInitCmd(t, "", "--folder", "/etc")
	if err == nil || !strings.Contains(err.Error(), "--folder takes root or current") {
		t.Fatalf("err = %v", err)
	}
	if w.created.Load() != 0 || len(w.execCalls) != 0 {
		t.Errorf("init went on: %v", w.execCalls)
	}
}

func TestInitFolderAsksOnATerminalAndEnterPicksTheRoot(t *testing.T) {
	for answer, wantRoot := range map[string]bool{"\n": true, "1\n": true, "2\n": false, "x\n2\n": false} {
		w := newInitWorld(t, devSite())
		sub, root := inSubfolder(t, w)

		out, err := runInitCmd(t, answer+"n\n", "--no-mcp", "--project", "dev")
		if err != nil {
			t.Fatalf("answer %q: %v\n%s", answer, err, out)
		}
		if !strings.Contains(out, "1) the repository root "+root+" (default)") || !strings.Contains(out, "2) this folder "+sub) {
			t.Errorf("answer %q: the question must show both folders:\n%s", answer, out)
		}
		if hasLink(t, root) != wantRoot || hasLink(t, sub) == wantRoot {
			t.Errorf("answer %q: project file at root = %v, in the subfolder = %v", answer, hasLink(t, root), hasLink(t, sub))
		}
	}
}

func TestInitFolderStopsWhenTheInputEndsBeforeAnAnswer(t *testing.T) {
	w := newInitWorld(t, devSite())
	sub, root := inSubfolder(t, w)

	_, err := runInitCmd(t, "", "--no-mcp", "--project", "dev")
	if err == nil || !strings.Contains(err.Error(), "--folder root") {
		t.Fatalf("err = %v", err)
	}
	if hasLink(t, sub) || hasLink(t, root) || w.created.Load() != 0 {
		t.Error("init wrote something")
	}
}

func TestInitFolderWithNoTerminalAndNoFlagStopsBeforeAnyWrite(t *testing.T) {
	w := newInitWorld(t, devSite())
	sub, root := inSubfolder(t, w)

	_, err := runInitWithIn(t, noTerminal(t), "--no-mcp", "--name", "Infra")
	if err == nil || !strings.Contains(err.Error(), root) || !strings.Contains(err.Error(), "--folder root") {
		t.Fatalf("err = %v, want the root and the flag", err)
	}
	path, _ := defaultRulesPath()
	if hasLink(t, sub) || hasLink(t, root) || w.created.Load() != 0 || readOrEmpty(t, path) != "" || w.bridgeStarts != 0 {
		t.Errorf("init wrote something: created = %d", w.created.Load())
	}
}

func TestInitFolderWithNoTerminalTakesTheFlag(t *testing.T) {
	w := newInitWorld(t, devSite())
	_, root := inSubfolder(t, w)

	out, err := runInitWithIn(t, noTerminal(t), "--no-mcp", "--project", "dev", "--folder", "root")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !hasLink(t, root) {
		t.Error("no project file at the root")
	}
}

func TestInitFolderSkipsAtTheRootAndOutsideGit(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--project", "dev", "--folder", "root")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Folder: skipped, "+dir+" is the repository root.") || !hasLink(t, dir) {
		t.Errorf("output:\n%s", out)
	}

	w = newInitWorld(t, devSite())
	dir = inRepo(t, w.server.URL)
	w.execFail["git rev-parse"] = true
	out, err = runInitCmd(t, "n\n", "--no-mcp", "--project", "dev")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Folder: skipped, "+dir+" is not in a git repository.") || !hasLink(t, dir) {
		t.Errorf("output:\n%s", out)
	}
}

func TestInitFolderResolvesARelativeRulesPathAgainstTheRoot(t *testing.T) {
	w := newInitWorld(t, devSite())
	sub, root := inSubfolder(t, w)
	var started bridgeRunOptions
	initStartBridge = func(_ *cobra.Command, o bridgeRunOptions) error {
		started = o
		w.bridgeStarts++

		return nil
	}

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--project", "dev", "--folder", "root", "--rules", "config/rules.yaml")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	want := filepath.Join(root, "config", "rules.yaml")
	if body := readOrEmpty(t, want); !strings.Contains(body, "dev:") {
		t.Errorf("rule file below the root:\n%s", body)
	}
	if _, err := os.Stat(filepath.Join(sub, "config")); err == nil {
		t.Error("init wrote the rule file below the subfolder")
	}
	if started.rulesPath != want {
		t.Errorf("bridge rules path = %q, want %q", started.rulesPath, want)
	}
	if !strings.Contains(out, "--rules "+want) && !strings.Contains(out, want) {
		t.Errorf("output must name %s:\n%s", want, out)
	}
}

func TestInitRepointAsksOnATerminalAndYesChangesOnlyThatDir(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	keep := t.TempDir()
	writeFile(t, path, "projects:\n  dev:\n    dir: /elsewhere\n  other:\n    dir: "+keep+"\n")

	out, err := runInitCmd(t, "\nn\n", "--no-mcp", "--project", "dev")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "projects.dev maps /elsewhere. Point it at "+dir+"? [Y/n]") || !strings.Contains(out, "projects.dev.dir") {
		t.Errorf("output:\n%s", out)
	}
	body := readOrEmpty(t, path)
	if !strings.Contains(body, "dir: "+dir) || strings.Contains(body, "/elsewhere") || !strings.Contains(body, "dir: "+keep) {
		t.Errorf("rule file:\n%s", body)
	}
}

func TestInitRepointNoStopsWithTheError(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	writeFile(t, path, "projects:\n  dev:\n    dir: /elsewhere\n")

	_, err := runInitCmd(t, "n\n", "--no-mcp", "--project", "dev")
	if err == nil || !strings.Contains(err.Error(), "projects.dev maps /elsewhere") || !strings.Contains(err.Error(), "--repoint") {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(readOrEmpty(t, path), "/elsewhere") || w.bridgeStarts != 0 {
		t.Error("init changed the rule file or went on")
	}
}

func TestInitRepointWithNoTerminalNeedsTheFlag(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	writeFile(t, path, "projects:\n  dev:\n    dir: /elsewhere\n")

	_, err := runInitWithIn(t, noTerminal(t), "--no-mcp", "--project", "dev")
	if err == nil || !strings.Contains(err.Error(), "--repoint") {
		t.Fatalf("err = %v", err)
	}

	out, err := runInitWithIn(t, noTerminal(t), "--no-mcp", "--project", "dev", "--repoint")
	if err != nil {
		t.Fatalf("init --repoint: %v\n%s", err, out)
	}
	if body := readOrEmpty(t, path); !strings.Contains(body, "dir: "+dir) || strings.Contains(body, "/elsewhere") {
		t.Errorf("rule file:\n%s", body)
	}
}

func TestInitRepointAlsoWorksWithReset(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	path, _ := defaultRulesPath()
	writeFile(t, path, "projects:\n  dev:\n    dir: /elsewhere\n")

	out, err := runInitWithIn(t, noTerminal(t), "--no-mcp", "--project", "dev", "--reset", "--repoint")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if body := readOrEmpty(t, path); !strings.Contains(body, "dir: "+dir) {
		t.Errorf("rule file:\n%s", body)
	}
}

const blogSite = `{"id":"01a0c0d9-0000-7000-8000-000000000001","slug":"blog","name":"Blog"}`

func takenBlog(w *initWorld) {
	w.createReply = func(body map[string]any) (int, string) {
		if body["name"] == "Blog" {
			return http.StatusConflict, `{"code":"name_taken","slug":"blog"}`
		}

		return http.StatusCreated, madeReply
	}
}

func TestInitNameReusesATakenProjectOnYes(t *testing.T) {
	w := newInitWorld(t, devSite()+","+blogSite)
	dir := inRepo(t, w.server.URL)
	takenBlog(w)

	out, err := runInitCmd(t, "\nn\n", "--no-mcp", "--name", "Blog", "--origin", "https://blog.test")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	for _, want := range []string{"You already have a project named Blog. Use it? [Y/n]", "Project: done, using Blog (blog, 01a0c0d9-0000-7000-8000-000000000001)", "--domain and --origin apply to a new project"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if file, _ := projectfile.Load(dir); file.Project != "01a0c0d9-0000-7000-8000-000000000001" || w.created.Load() != 1 {
		t.Errorf("project = %q, created = %d", file.Project, w.created.Load())
	}
}

func TestInitNameAsksForAnotherNameOnNo(t *testing.T) {
	w := newInitWorld(t, devSite()+","+blogSite)
	inRepo(t, w.server.URL)
	takenBlog(w)
	w.execOut["git remote get-url origin"] = "git@github.com:acme/app.git\n"

	out, err := runInitCmd(t, "n\nBlog 2\nn\n", "--no-mcp", "--name", "Blog")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if body, _ := w.createBody.Load().(map[string]any); body["name"] != "Blog 2" || !strings.Contains(out, "Project: done, created Made") {
		t.Errorf("create body = %v\n%s", body, out)
	}
}

func TestInitNameWithNoTerminalKeepsTheError(t *testing.T) {
	w := newInitWorld(t, devSite()+","+blogSite)
	dir := inRepo(t, w.server.URL)
	takenBlog(w)

	_, err := runInitWithIn(t, noTerminal(t), "--no-mcp", "--name", "Blog")
	want := "you already have a project with this name, blog: pass --project blog to use it, or choose another name"
	if err == nil || err.Error() != want {
		t.Fatalf("err = %v, want %q", err, want)
	}
	if hasLink(t, dir) {
		t.Error("init wrote the project file")
	}
}

func TestInitPickerReusesATakenProjectOnYes(t *testing.T) {
	w := newInitWorld(t, devSite()+","+blogSite)
	inRepo(t, w.server.URL)
	takenBlog(w)
	w.execOut["git remote get-url origin"] = "git@github.com:acme/app.git\n"

	out, err := runInitCmd(t, "3\nBlog\ny\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if !strings.Contains(out, "Project: done, using Blog (blog,") || w.created.Load() != 1 {
		t.Errorf("created = %d\n%s", w.created.Load(), out)
	}
}

func TestInitPrintsTheProjectNameOnceWhenTheSlugMatches(t *testing.T) {
	if got := describe(api.Site{Name: "make-plans", Slug: "make-plans", ID: "id-1"}); got != "make-plans (id-1)" {
		t.Errorf("describe = %q", got)
	}
	if got := describe(api.Site{Name: "Dev Project", Slug: "dev", ID: "id-1"}); got != "Dev Project (dev, id-1)" {
		t.Errorf("describe = %q", got)
	}
}

func TestInitLinkPrintsNoSentenceOutsideTheStepFormat(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "n\n", "--no-mcp", "--project", "dev")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	if strings.Contains(out, "Wrote .loupe.yaml") || !strings.Contains(out, "Link: done, .loupe.yaml names the project") {
		t.Errorf("output:\n%s", out)
	}
}
