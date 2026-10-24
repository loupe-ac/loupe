package cmd

import (
	"bytes"
	"errors"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
)

func twoSites() string {
	return `{"id":"` + firstProject + `","slug":"dev","name":"Dev Project"},` +
		`{"id":"` + secondProject + `","slug":"","name":"Beta"}`
}

// runList runs `loupe project list` and gives stdout and stderr apart.
func runList(t *testing.T) (string, string, error) {
	t.Helper()
	var out, errOut bytes.Buffer
	cmd := newProjectListCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&errOut)
	cmd.SetArgs(nil)
	err := cmd.Execute()

	return out.String(), errOut.String(), err
}

func TestProjectListPrintsOneRowForEachProject(t *testing.T) {
	server := projectsServer(t, twoSites())
	inRepo(t, server.URL)

	out, _, err := runList(t)
	if err != nil {
		t.Fatalf("project list: %v", err)
	}
	want := "  ID                                    SLUG  NAME\n" +
		"  " + firstProject + "  dev   Dev Project\n" +
		"  " + secondProject + "  -     Beta\n"
	if out != want {
		t.Fatalf("output:\n%s\nwant:\n%s", out, want)
	}
}

func TestProjectListMarksTheProjectOfTheProjectFile(t *testing.T) {
	server := projectsServer(t, twoSites())
	dir := inRepo(t, server.URL)
	if err := projectfile.Write(dir, secondProject); err != nil {
		t.Fatal(err)
	}

	out, _, err := runList(t)
	if err != nil {
		t.Fatalf("project list: %v", err)
	}
	if !strings.Contains(out, "* "+secondProject+"  -     Beta\n") || !strings.Contains(out, "  "+firstProject) {
		t.Fatalf("output must mark the second project only:\n%s", out)
	}
	if !strings.HasSuffix(out, "\n* marks the project that .loupe.yaml names.\n") {
		t.Fatalf("output must name the source of the mark:\n%s", out)
	}
}

func TestProjectListSaysWhenTheProjectFileNamesAnUnlistedProject(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"dev","name":"Dev Project"}`)
	dir := inRepo(t, server.URL)
	if err := projectfile.Write(dir, secondProject); err != nil {
		t.Fatal(err)
	}

	out, _, err := runList(t)
	if err != nil {
		t.Fatalf("project list: %v", err)
	}
	if strings.Contains(out, "* ") || !strings.HasSuffix(out, "\n.loupe.yaml names project "+secondProject+", which your login does not cover.\n") {
		t.Fatalf("output must mark nothing and say why:\n%s", out)
	}
}

func TestProjectListStillListsWhenTheProjectFileIsBroken(t *testing.T) {
	server := projectsServer(t, twoSites())
	dir := inRepo(t, server.URL)
	writeFile(t, dir+"/"+projectfile.Name, "project: not-an-id\n")

	out, errOut, err := runList(t)
	if err != nil {
		t.Fatalf("project list: %v", err)
	}
	if !strings.Contains(out, firstProject) || strings.Contains(out, "* ") {
		t.Fatalf("output must list the projects with no mark:\n%s", out)
	}
	if !strings.Contains(errOut, "project must be a project id") {
		t.Fatalf("stderr must say what is wrong with the file, got %q", errOut)
	}
}

func TestProjectListWithNoProjectSaysHowToCreateOne(t *testing.T) {
	server := projectsServer(t, "")
	inRepo(t, server.URL)

	out, _, err := runList(t)
	if err != nil {
		t.Fatalf("project list: %v", err)
	}
	want := "Your login covers no project. Create one with `loupe init --name \"<name>\"`, or in the app.\n"
	if out != want {
		t.Fatalf("output: got %q, want %q", out, want)
	}
}

func TestProjectListWithNoLoginAsksForOne(t *testing.T) {
	inRepo(t, "")

	_, _, err := runList(t)
	if !errors.Is(err, config.ErrNotLoggedIn) {
		t.Fatalf("project list with no login: got %v, want ErrNotLoggedIn", err)
	}
}

func TestProjectListInASecondSetupMarksTheProjectOfTheLink(t *testing.T) {
	server := projectsServer(t, twoSites())
	dir, setupDir := secondSetup(t, server.URL)
	if err := projectfile.WriteLink(setupDir, projectfile.LinkKey(dir), firstProject); err != nil {
		t.Fatal(err)
	}

	out, _, err := runList(t)
	if err != nil {
		t.Fatalf("project list: %v", err)
	}
	if !strings.Contains(out, "* "+firstProject) || strings.Contains(out, "* "+secondProject) {
		t.Fatalf("output must mark the linked project, not the one of .loupe.yaml:\n%s", out)
	}
	if !strings.Contains(out, "* marks the project that the project link of the setup "+setupDir+" names.") {
		t.Fatalf("output must name the link as the source:\n%s", out)
	}
}

func TestProjectListInASecondSetupFallsBackToTheProjectFile(t *testing.T) {
	server := projectsServer(t, twoSites())
	secondSetup(t, server.URL)

	out, _, err := runList(t)
	if err != nil {
		t.Fatalf("project list: %v", err)
	}
	if !strings.Contains(out, "* "+secondProject) || !strings.Contains(out, "* marks the project that .loupe.yaml names.") {
		t.Fatalf("output must mark the project of .loupe.yaml:\n%s", out)
	}
}

func TestProjectListInASecondSetupMarksNothingWhenTheLinkFileIsBroken(t *testing.T) {
	server := projectsServer(t, twoSites())
	_, setupDir := secondSetup(t, server.URL)
	writeFile(t, setupDir+"/"+projectfile.LinksName, "not json")

	out, errOut, err := runList(t)
	if err != nil {
		t.Fatalf("project list: %v", err)
	}
	if strings.Contains(out, "* ") || !strings.Contains(out, secondProject) {
		t.Fatalf("output must list the projects and mark none, not the one of .loupe.yaml:\n%s", out)
	}
	if !strings.Contains(errOut, "read the project links of the setup "+setupDir) {
		t.Fatalf("stderr must say the link file is broken, got %q", errOut)
	}
}
