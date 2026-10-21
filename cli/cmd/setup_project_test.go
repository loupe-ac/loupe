package cmd

import (
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/profile"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
	"github.com/loupe-ac/loupe/cli/internal/update"
)

// secondSetup puts a checkout whose .loupe.yaml names a project that the
// login of the setup does not cover, and returns the checkout and the setup.
func secondSetup(t *testing.T, baseURL string) (dir, setupDir string) {
	t.Helper()
	dir = inRepo(t, baseURL)
	if err := projectfile.Write(dir, secondProject); err != nil {
		t.Fatal(err)
	}
	setupDir = filepath.Join(t.TempDir(), "my setup")
	if _, err := config.Resolve(setupDir); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _, _ = config.Resolve("") })
	if err := config.Save(testLogin(baseURL)); err != nil {
		t.Fatal(err)
	}

	return dir, setupDir
}

func linkOf(t *testing.T, setupDir, dir string) string {
	t.Helper()
	got, err := projectfile.LoadLink(setupDir, projectfile.LinkKey(dir))
	if err != nil {
		t.Fatal(err)
	}

	return got
}

func yamlUnchanged(t *testing.T, dir string) {
	t.Helper()
	file, err := projectfile.Load(dir)
	if err != nil || file.Project != secondProject {
		t.Errorf(".loupe.yaml = %+v, %v; want it unchanged", file, err)
	}
	for _, name := range []string{profile.Path, ".mcp.json"} {
		if _, err := os.Stat(filepath.Join(dir, name)); err == nil {
			t.Errorf("%s was written in a second setup", name)
		}
	}
}

func TestInitInASecondSetupLinksInTheSetupAndLeavesTheRepositoryAlone(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.claude, w.codex = true, true
	dir, setupDir := secondSetup(t, w.server.URL)

	out, err := runInitCmd(t, "n\n", "--project", firstProject)
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	if got := linkOf(t, setupDir, dir); got != firstProject {
		t.Errorf("link = %q, want %q", got, firstProject)
	}
	yamlUnchanged(t, dir)
	for _, want := range []string{
		"Link: done", "Plugin: skipped, a second setup leaves shared files alone",
		"Codex: skipped, a second setup leaves shared files alone",
		"Playwright: skipped, a second setup leaves shared files alone",
		"Rules: done", "Bridge: done",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if _, err := os.Stat(filepath.Join(setupDir, "rules.yaml")); err != nil {
		t.Errorf("init did not write the rules into the setup: %v", err)
	}
	for _, call := range w.execCalls {
		if strings.HasPrefix(call, "claude") || strings.HasPrefix(call, "codex") || strings.Contains(call, "playwright") {
			t.Errorf("a second setup ran %q", call)
		}
	}
	if w.bridgeStarts != 1 {
		t.Errorf("bridge starts = %d, want 1", w.bridgeStarts)
	}

	out, err = runInitCmd(t, "n\n", "--project", firstProject)
	if err != nil || !strings.Contains(out, "Link: skipped") {
		t.Errorf("a second run must change nothing: %v\n%s", err, out)
	}
}

func TestProjectLinkInASecondSetupLinksInTheSetup(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir, setupDir := secondSetup(t, w.server.URL)

	out, err := runLink(t, "", "--project", firstProject)
	if err != nil {
		t.Fatalf("project link: %v\n%s", err, out)
	}
	if got := linkOf(t, setupDir, dir); got != firstProject {
		t.Errorf("link = %q", got)
	}
	yamlUnchanged(t, dir)

	_, err = runLink(t, "", "--project", secondProject)
	if err == nil || !strings.Contains(err.Error(), "--force") {
		t.Errorf("a second link without --force must stop: %v", err)
	}
	if _, err := runLink(t, "", "--project", secondProject, "--force"); err != nil {
		t.Fatal(err)
	}
	if got := linkOf(t, setupDir, dir); got != secondProject {
		t.Errorf("link after --force = %q", got)
	}
}

func TestInitInASecondSetupStopsOnAnotherProjectUnlessForced(t *testing.T) {
	w := newInitWorld(t, devSite()+`,{"id":"`+secondProject+`","slug":"other","name":"Other"}`)
	dir, setupDir := secondSetup(t, w.server.URL)
	if err := projectfile.WriteLink(setupDir, projectfile.LinkKey(dir), firstProject); err != nil {
		t.Fatal(err)
	}

	out, err := runInitCmd(t, "n\n", "--project", secondProject)
	want := "the setup " + setupDir + " links this folder to Dev Project (dev, " + firstProject + "): pass --force to replace the link, or leave out --project"
	if err == nil || err.Error() != want {
		t.Fatalf("err = %v, want %q\n%s", err, want, out)
	}
	if got := linkOf(t, setupDir, dir); got != firstProject {
		t.Errorf("the refusal changed the link to %q", got)
	}

	out, err = runInitCmd(t, "n\n", "--project", secondProject, "--force")
	if err != nil {
		t.Fatalf("init --force: %v\n%s", err, out)
	}
	if got := linkOf(t, setupDir, dir); got != secondProject {
		t.Errorf("link after --force = %q", got)
	}
	yamlUnchanged(t, dir)
}

func TestInitInASecondSetupNamesTheSetupWhenTheLinkIsNotCovered(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir, setupDir := secondSetup(t, w.server.URL)
	if err := projectfile.WriteLink(setupDir, projectfile.LinkKey(dir), secondProject); err != nil {
		t.Fatal(err)
	}

	_, err := runInitCmd(t, "n\n")
	if err == nil || !strings.Contains(err.Error(), "the setup "+setupDir+" links this folder to "+secondProject) {
		t.Fatalf("err = %v", err)
	}
}

func TestMcpProjectReadsTheSetupLinkFirstThenTheProjectFile(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir, setupDir := secondSetup(t, w.server.URL)
	var notes strings.Builder

	project, source, err := mcpProjectSource("", &notes)
	if err != nil {
		t.Fatal(err)
	}
	if got := project(); got != secondProject || source() != projectfile.Name {
		t.Fatalf("with no link: %q from %q", got, source())
	}

	if err := projectfile.WriteLink(setupDir, projectfile.LinkKey(dir), firstProject); err != nil {
		t.Fatal(err)
	}
	if got := project(); got != firstProject || !strings.Contains(source(), setupDir) {
		t.Fatalf("with a link: %q from %q", got, source())
	}
	if !strings.Contains(notes.String(), "now names project "+firstProject) {
		t.Errorf("notes = %q", notes.String())
	}
}

func TestMcpProjectInASetupWithALinkIgnoresAMalformedProjectFile(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir, setupDir := secondSetup(t, w.server.URL)
	if err := os.WriteFile(filepath.Join(dir, projectfile.Name), []byte("project: [\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	var notes strings.Builder

	if _, _, err := mcpProjectSource("", &notes); err == nil {
		t.Fatal("a malformed project file with no link did not fail")
	}

	if err := projectfile.WriteLink(setupDir, projectfile.LinkKey(dir), firstProject); err != nil {
		t.Fatal(err)
	}
	project, source, err := mcpProjectSource("", &notes)
	if err != nil {
		t.Fatalf("a valid link failed on the malformed project file: %v", err)
	}
	if got := project(); got != firstProject || !strings.Contains(source(), setupDir) {
		t.Fatalf("with a link: %q from %q", got, source())
	}
}

// A link file that fails to read must never hand the project to .loupe.yaml,
// which names the project of the default setup.
func TestMcpProjectInASetupNeverFallsBackOnAMalformedLinkFile(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir, setupDir := secondSetup(t, w.server.URL)
	links := filepath.Join(setupDir, projectfile.LinksName)
	if err := os.WriteFile(links, []byte("{\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, _, err := mcpProjectSource("", io.Discard); err == nil || !strings.Contains(err.Error(), links) {
		t.Fatalf("a malformed link file at start: err = %v", err)
	}

	if err := os.Remove(links); err != nil {
		t.Fatal(err)
	}
	if err := projectfile.WriteLink(setupDir, projectfile.LinkKey(dir), firstProject); err != nil {
		t.Fatal(err)
	}
	var notes strings.Builder
	project, source, err := mcpProjectSource("", &notes)
	if err != nil {
		t.Fatal(err)
	}
	if got := project(); got != firstProject {
		t.Fatalf("with a link: %q", got)
	}
	if err := os.WriteFile(links, []byte("{\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := project(); got != firstProject || !strings.Contains(source(), setupDir) {
		t.Fatalf("after the link file broke: %q from %q", got, source())
	}
	if !strings.Contains(notes.String(), links) {
		t.Errorf("notes = %q", notes.String())
	}
}

func TestMcpProjectInASetupKeepsTheLinkReadAtStartWhenTheLinkFileBreaksBeforeTheFirstRequest(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir, setupDir := secondSetup(t, w.server.URL)
	if err := projectfile.WriteLink(setupDir, projectfile.LinkKey(dir), firstProject); err != nil {
		t.Fatal(err)
	}
	project, source, err := mcpProjectSource("", io.Discard)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(setupDir, projectfile.LinksName), []byte("{\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := project(); got != firstProject || !strings.Contains(source(), setupDir) {
		t.Fatalf("after the link file broke: %q from %q", got, source())
	}
}

func TestMcpProjectInASetupSendsNoProjectWhenTheLinkFileBreaksBeforeAnyLink(t *testing.T) {
	w := newInitWorld(t, devSite())
	_, setupDir := secondSetup(t, w.server.URL)
	project, _, err := mcpProjectSource("", io.Discard)
	if err != nil {
		t.Fatal(err)
	}
	if got := project(); got != secondProject {
		t.Fatalf("with no link: %q", got)
	}
	if err := os.WriteFile(filepath.Join(setupDir, projectfile.LinksName), []byte("{\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := project(); got != "" {
		t.Fatalf("a broken link file fell back to %q", got)
	}
}

func TestMcpProjectInTheDefaultSetupStillFailsOnAMalformedProjectFile(t *testing.T) {
	dir := inRepo(t, newInitWorld(t, devSite()).server.URL)
	if err := os.WriteFile(filepath.Join(dir, projectfile.Name), []byte("project: [\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, _, err := mcpProjectSource("", io.Discard); err == nil {
		t.Fatal("a malformed project file in the default setup did not fail")
	}
}

func TestReviewIOSPassesTheSetupLinkBeforeTheProjectFile(t *testing.T) {
	h := newIOSHarness(t)
	t.Cleanup(func() { _, _ = config.Resolve("") })
	if err := os.WriteFile(filepath.Join(h.cwd, ".loupe.yaml"), []byte("project: "+secondProject+"\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	setupDir := filepath.Join(h.root, "setup")
	if _, err := config.Resolve(setupDir); err != nil {
		t.Fatal(err)
	}
	h.installPanel(t, filepath.Join(update.PanelDir(setupDir, "1.2.3"), update.PanelAppName))
	if err := projectfile.WriteLink(setupDir, projectfile.LinkKey(h.cwd), firstProject); err != nil {
		t.Fatal(err)
	}

	if _, err := h.run(); err != nil {
		t.Fatal(err)
	}
	got := h.opened[0]
	if tail := got[len(got)-2:]; tail[0] != "--project" || tail[1] != firstProject {
		t.Fatalf("open = %v", got)
	}
}
