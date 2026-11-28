package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

const porcelain = `worktree /repo
HEAD 1111111111111111111111111111111111111111
branch refs/heads/main

worktree /repo/.worktrees/card 42
HEAD 2222222222222222222222222222222222222222
branch refs/heads/epic/42

worktree /repo/.worktrees/detached
HEAD 3333333333333333333333333333333333333333
detached

worktree /bare
bare
`

func TestParseWorktreesKeepsBranchesOnly(t *testing.T) {
	got := parseWorktrees(porcelain)
	want := []worktree{{"main", "/repo"}, {"epic/42", "/repo/.worktrees/card 42"}}
	if !slices.Equal(got, want) {
		t.Fatalf("got %+v, want %+v", got, want)
	}
	if got := parseWorktrees(""); len(got) != 0 {
		t.Fatalf("empty input gave %+v", got)
	}
}

func commandSet(t *testing.T, extra string) *rules.Set {
	t.Helper()
	dir := t.TempDir()
	text := "projects:\n  loupe:\n    dir: " + dir + "\n" + extra +
		"work:\n  zeta:\n    action: command\n    run: [z]\n    timeout: 2m\n" +
		"  alpha:\n    action: command\n    description: A\n    params:\n      b: {pattern: 'x+'}\n      a: {pattern: y, description: D}\n    run: [a, '{a}', '{b}']\n"
	set, err := rules.Parse([]byte(text), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}

	return set
}

func TestCommandEntryReportsAreSortedWithTheirParams(t *testing.T) {
	got := commandEntryReports(commandSet(t, ""))
	if len(got) != 2 || got[0].Name != "alpha" || got[1].Name != "zeta" {
		t.Fatalf("got %+v", got)
	}
	a := got[0]
	if a.Description != "A" || a.Timeout != "10m" || !slices.Equal(a.Run, []string{"a", "{a}", "{b}"}) {
		t.Fatalf("alpha = %+v", a)
	}
	if len(a.Params) != 2 || a.Params[0] != (api.CommandParamReport{Name: "a", Pattern: "y", Description: "D"}) || a.Params[1].Name != "b" {
		t.Fatalf("params = %+v", a.Params)
	}
	if got[1].Timeout != "2m" || got[1].Params == nil {
		t.Fatalf("zeta = %+v", got[1])
	}
}

// layeredSet follows loupe and plans, each with a command of its own. loupe
// hides the shared build command with a worker, and so does plans.
func layeredSet(t *testing.T, shared string) *rules.Set {
	t.Helper()
	text := "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n" +
		"  loupe:\n    dir: " + t.TempDir() + "\n    work:\n      build:\n        prompt: x\n      ship:\n        action: command\n        run: [ship]\n" +
		"  plans:\n    dir: " + t.TempDir() + "\n    work:\n      build:\n        prompt: x\n      deploy:\n        action: command\n        run: [deploy]\n" +
		"work:\n  build:\n    action: command\n    run: [make]\n" + shared
	set, err := rules.Parse([]byte(text), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}

	return set
}

// resolved runs Check, which gives each project its id from ids.
func resolved(t *testing.T, set *rules.Set, ids map[string]string) *rules.Set {
	t.Helper()
	if err := set.Check(context.Background(), projectIDs(ids)); err != nil {
		t.Fatal(err)
	}

	return set
}

type projectIDs map[string]string

func (p projectIDs) Columns(_ context.Context, handle string) (api.ProjectColumns, error) {
	var pc api.ProjectColumns
	pc.Project.ID, pc.Project.Slug = p[handle], handle

	return pc, nil
}

func (p projectIDs) Sites(context.Context) ([]api.Site, error) { return nil, nil }

const (
	loupeID = "0192f3a1-4b2c-7d3e-8f10-000000000011"
	plansID = "0192f3a1-4b2c-7d3e-8f10-000000000012"
)

func TestHeartbeatBodyNamesTheProjectsOfEachCommandRow(t *testing.T) {
	set := resolved(t, layeredSet(t, ""), map[string]string{"loupe": loupeID, "plans": plansID})

	got, err := json.Marshal(heartbeatBody(set).CommandEntries)
	if err != nil {
		t.Fatal(err)
	}

	want := `[{"name":"build","description":"","params":[],"run":["make"],"timeout":"10m","exceptProjects":["` + loupeID + `","` + plansID + `"]},` +
		`{"name":"ship","description":"","params":[],"run":["ship"],"timeout":"10m","projectId":"` + loupeID + `"},` +
		`{"name":"deploy","description":"","params":[],"run":["deploy"],"timeout":"10m","projectId":"` + plansID + `"}]`
	if string(got) != want {
		t.Fatalf("commandEntries =\n%s\nwant\n%s", got, want)
	}
}

// The server reads an empty projectId as shared, so a row of a project with no
// id must not go out.
func TestCommandEntryReportsDropAProjectWithNoID(t *testing.T) {
	// No Check ran, so no project has an id.
	got := commandEntryReports(layeredSet(t, ""))

	if len(got) != 1 || got[0].Name != "build" || got[0].ProjectID != "" || len(got[0].ExceptProjects) != 0 {
		t.Fatalf("got %+v", got)
	}
}

func TestCommandEntryReportsStayAnEmptyListWhenEveryRowIsDropped(t *testing.T) {
	set, err := rules.Parse([]byte("projects:\n  loupe:\n    dir: "+t.TempDir()+"\n    work:\n      ship:\n        action: command\n        run: [ship]\n"), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}

	if got := commandEntryReports(set); got == nil || len(got) != 0 {
		t.Fatalf("got %#v, want an empty list that clears the stored rows", got)
	}
}

// sharedCommands lists n shared command entries.
func sharedCommands(n int) string {
	var b strings.Builder
	for i := range n {
		fmt.Fprintf(&b, "  c%02d:\n    action: command\n    run: [make]\n", i)
	}

	return b.String()
}

func TestACutDropsProjectRowsAndTheBridgeLogsIt(t *testing.T) {
	// build and 48 more shared rows leave room for one of the two project rows.
	set := resolved(t, layeredSet(t, sharedCommands(api.MaxCommandEntries-2)), map[string]string{"loupe": loupeID, "plans": plansID})

	got := commandEntryReports(set)
	if len(got) != api.MaxCommandEntries || got[len(got)-1].Name != "ship" {
		t.Fatalf("got %d rows, last %+v", len(got), got[len(got)-1])
	}
	var out bytes.Buffer
	warnCommandEntriesCut(newBridgeLogger(&out), set)
	var line map[string]any
	if err := json.Unmarshal(out.Bytes(), &line); err != nil {
		t.Fatalf("log = %q: %v", out.String(), err)
	}
	if line["event"] != "command_entries_cut" || line["level"] != "WARN" || line["limit"] != float64(api.MaxCommandEntries) || fmt.Sprint(line["dropped"]) != "[plans/deploy]" {
		t.Fatalf("line = %v", line)
	}
}

func TestASetAtTheLimitLogsNoCut(t *testing.T) {
	set := resolved(t, layeredSet(t, sharedCommands(api.MaxCommandEntries-3)), map[string]string{"loupe": loupeID, "plans": plansID})
	var out bytes.Buffer

	warnCommandEntriesCut(newBridgeLogger(&out), set)

	if len(commandEntryReports(set)) != api.MaxCommandEntries || out.Len() != 0 {
		t.Fatalf("log = %q", out.String())
	}
}

func TestHeartbeatBodyAddsTheEntryCapabilityOnOptIn(t *testing.T) {
	if slices.Contains(heartbeatBody(commandSet(t, "")).Capabilities, rules.CapabilityAddEntry) {
		t.Error("add-entry without acceptEntries")
	}
	hb := heartbeatBody(commandSet(t, "acceptEntries: true\n"))
	if !slices.Contains(hb.Capabilities, rules.CapabilityAddEntry) || len(hb.CommandEntries) != 2 {
		t.Fatalf("body = %+v", hb)
	}
}

func TestWorktreeReportsReadGitAndOmitTheListOnFailure(t *testing.T) {
	if _, err := exec.LookPath("git"); err != nil {
		t.Skip("git is missing")
	}
	set := commandSet(t, "")
	dir := set.Dir("loupe")
	if got := worktreeReports(context.Background(), set); got != nil {
		t.Fatalf("a folder with no repository gave %+v", got)
	}
	for _, args := range [][]string{{"init", "-b", "main"}, {"-c", "user.name=t", "-c", "user.email=t@t", "commit", "--allow-empty", "-m", "i"}} {
		if out, err := exec.Command("git", append([]string{"-C", dir}, args...)...).CombinedOutput(); err != nil {
			t.Fatalf("git %v: %v %s", args, err, out)
		}
	}
	got := worktreeReports(context.Background(), set)
	real, _ := filepath.EvalSymlinks(dir)
	if len(got) != 1 || got[0].Branch != "main" || got[0].ProjectID != set.ProjectID("loupe") || !strings.HasSuffix(got[0].Path, filepath.Base(real)) {
		t.Fatalf("got %+v", got)
	}
}

func TestOriginRemoteReadsGitAndDropsCredentials(t *testing.T) {
	if _, err := exec.LookPath("git"); err != nil {
		t.Skip("git is missing")
	}
	dir := t.TempDir()
	if got := originRemote(context.Background(), dir); got != "" {
		t.Fatalf("a folder with no repository gave %q", got)
	}
	for _, args := range [][]string{{"init", "-b", "main"}, {"remote", "add", "origin", "https://user:secret@github.com/loupe-ac/loupe.git"}} {
		if out, err := exec.Command("git", append([]string{"-C", dir}, args...)...).CombinedOutput(); err != nil {
			t.Fatalf("git %v: %v %s", args, err, out)
		}
	}
	if got := originRemote(context.Background(), dir); got != "https://github.com/loupe-ac/loupe.git" {
		t.Fatalf("got %q", got)
	}
}

func TestRepositoryReportsSkipAProjectWithNoResolvedID(t *testing.T) {
	got := repositoryReports(context.Background(), commandSet(t, ""))
	if got == nil || len(got) != 0 {
		t.Fatalf("got %+v, want an empty list that clears the stored rows", got)
	}
}

func TestWithoutCredentialsKeepsAnScpRemoteAndDropsTheOverlongOne(t *testing.T) {
	for in, want := range map[string]string{
		"git@github.com:loupe-ac/loupe.git":     "git@github.com:loupe-ac/loupe.git",
		"https://github.com/loupe-ac/loupe.git": "https://github.com/loupe-ac/loupe.git",
		"https://tok@github.com/loupe-ac/l.git": "https://github.com/loupe-ac/l.git",
		"":                                      "",
		strings.Repeat("a", maxRemoteURL+1):     "",
	} {
		if got := withoutCredentials(in); got != want {
			t.Errorf("withoutCredentials(%q) = %q, want %q", in, got, want)
		}
	}
}
