package cmd

import (
	"context"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
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

func TestHeartbeatBodyAddsTheEntryCapabilityOnOptIn(t *testing.T) {
	if slices.Contains(heartbeatBody(commandSet(t, ""), "").Capabilities, rules.CapabilityAddEntry) {
		t.Error("add-entry without acceptEntries")
	}
	hb := heartbeatBody(commandSet(t, "acceptEntries: true\n"), "")
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
