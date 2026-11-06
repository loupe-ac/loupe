package cmd

import (
	"bytes"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/rules"
)

const checkFile = "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\n"

func runBridgeCheck(t *testing.T, args ...string) (string, error) {
	t.Helper()
	var out, errOut bytes.Buffer
	cmd := newRootCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&errOut)
	cmd.SetArgs(append([]string{"bridge", "check"}, args...))
	err := cmd.Execute()

	return out.String(), err
}

func writeCheckRules(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func TestBridgeCheckListsWhatAProjectCannotRun(t *testing.T) {
	loupe, plans := t.TempDir(), t.TempDir()
	path := writeCheckRules(t, checkFile+"projects:\n  loupe:\n    dir: "+loupe+"\n    work:\n      review:\n        prompt: x\n        before:\n          run: [bin/before.sh]\n  plans:\n    dir: "+plans+"\nwork:\n  fix:\n    prompt: x\n")

	out, err := runBridgeCheck(t, "--rules", path)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"2 warning(s)", "project loupe: work review before runs bin/before.sh, which does not exist in " + loupe, "project plans: lists no work entry review"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestBridgeCheckPassesAFileWithNoWarning(t *testing.T) {
	path := writeCheckRules(t, checkFile+"projects:\n  loupe:\n    dir: "+t.TempDir()+"\nwork:\n  fix:\n    prompt: x\n")

	out, err := runBridgeCheck(t, "--rules", path)
	if err != nil || !strings.Contains(out, "loads, and every project can run every kind it lists") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
}

func TestBridgeCheckFailsOnAFileThatDoesNotLoad(t *testing.T) {
	path := writeCheckRules(t, checkFile+"projects:\n  loupe:\n    dir: "+t.TempDir()+"\n    work:\n      x:\n        action: command\n        run: [make]\n")

	if _, err := runBridgeCheck(t, "--rules", path); err == nil || !strings.Contains(err.Error(), "not allowed under a project") {
		t.Fatalf("err = %v", err)
	}
}

func TestBridgeStatusShowsTheWarnings(t *testing.T) {
	shortConfigHome(t)
	res := validStatus("laptop")
	res.Warnings = []string{"project plans: lists no work entry review, which another project lists"}
	fakeBridge(t, "rules.yaml", statusOps(res))

	out, err := runBridgeStatus(t)
	if err != nil || !strings.Contains(out, "Warning:     project plans: lists no work entry review") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
}

func TestDiffRulesNamesAProjectEntryWithItsSlug(t *testing.T) {
	parse := func(prompt string, shared bool) *rules.Set {
		t.Helper()
		body := checkFile + "projects:\n  loupe:\n    dir: " + t.TempDir() + "\n    work:\n      review:\n        prompt: " + prompt + "\n"
		if shared {
			body += "work:\n  fix:\n    prompt: x\n"
		}
		set, err := rules.Parse([]byte(body), rules.Defaults{})
		if err != nil {
			t.Fatal(err)
		}

		return set
	}

	res := diffRules(parse("one", true), parse("two", false))
	if !slices.Equal(res.Changed, []string{"loupe/review"}) || !slices.Equal(res.Removed, []string{"fix"}) || len(res.Added) != 0 {
		t.Fatalf("result = %+v", res)
	}
	res = diffRules(parse("one", false), parse("one", true))
	if !slices.Equal(res.Added, []string{"fix"}) || len(res.Changed) != 0 {
		t.Fatalf("result = %+v", res)
	}
}
