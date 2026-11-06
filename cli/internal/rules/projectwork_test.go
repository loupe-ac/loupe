package rules

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

const otherProjectID = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f8"

const otherColumns = `{"project":{"id":"` + otherProjectID + `","slug":"plans"},"columns":[{"slug":"backlog"},{"slug":"ready"},{"slug":"review"},{"slug":"done","terminal":true}]}`

// twoProjects follows loupe and plans. Only loupe lists review and its own
// implement entry.
func twoProjects(t *testing.T, extra string) (*Set, string, string) {
	t.Helper()
	loupeDir, plansDir := t.TempDir(), t.TempDir()
	body := claudeAccount + claudeDefaults + `
projects:
  loupe:
    dir: ` + loupeDir + `
    work:
      implement:
        prompt: Loupe implement {cardNumber}.
        before:
          run: [bin/before.sh]
      review:
        prompt: Review {cardNumber}.
  plans:
    dir: ` + plansDir + `
work:
  implement:
    prompt: Shared implement {cardNumber}.
  fix:
    prompt: Fix {cardNumber}.
` + extra
	s, err := Parse([]byte(body), Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := s.Check(t.Context(), columnsServer(t, map[string]string{"loupe": loupeColumns, "plans": otherColumns})); err != nil {
		t.Fatal(err)
	}

	return s, loupeDir, plansDir
}

func requestFor(projectID, kind string) api.WorkRequest {
	w := workRequest(kind)
	w.ProjectID = projectID

	return w
}

func TestAProjectEntryReplacesTheSharedEntryForThatProject(t *testing.T) {
	s, loupeDir, plansDir := twoProjects(t, "")

	m := s.MatchWork(requestFor(projectID, "implement"))
	if m.Skip != Run || !strings.HasPrefix(m.Prompt, "Loupe implement") || m.Before == nil || m.Dir != loupeDir {
		t.Fatalf("loupe match = %+v", m)
	}
	m = s.MatchWork(requestFor(otherProjectID, "implement"))
	if m.Skip != Run || !strings.HasPrefix(m.Prompt, "Shared implement") || m.Before != nil || m.Dir != plansDir {
		t.Fatalf("plans match = %+v", m)
	}
}

func TestAKindOnlyAProjectListsIsAdvertisedClaimedAndRunForThatProject(t *testing.T) {
	s, _, _ := twoProjects(t, "")

	if got := s.WorkKindsOn(); !slices.Contains(got, "review") {
		t.Fatalf("WorkKindsOn = %v", got)
	}
	if got := s.WorkKinds(); !slices.Equal(got, []string{"fix", "implement", "review"}) {
		t.Fatalf("WorkKinds = %v", got)
	}
	if got := s.AccountKinds("claude"); !slices.Contains(got, "review") {
		t.Fatalf("AccountKinds = %v", got)
	}
	if !s.HasWork() || len(s.Capabilities()) == 0 {
		t.Fatalf("capabilities = %v", s.Capabilities())
	}
	if m := s.MatchWork(requestFor(projectID, "review")); m.Skip != Run || m.Rule != "work:review" {
		t.Fatalf("loupe review = %+v", m)
	}
	if m := s.MatchWork(requestFor(otherProjectID, "review")); m.Skip != NoRule {
		t.Fatalf("plans review = %+v", m)
	}
}

func TestAProjectWithoutTheKindRunsTheAppPromptWhenTheSetHasOne(t *testing.T) {
	s, _, _ := twoProjects(t, "appPrompts: true\n")
	w := requestFor(otherProjectID, "review")
	w.Prompt = "Review it."

	if m := s.MatchWork(w); m.Skip != Run || m.Prompt == "" {
		t.Fatalf("match = %+v", m)
	}
}

func TestAProjectOnlyFileLoads(t *testing.T) {
	dir := t.TempDir()
	s, err := Parse([]byte(claudeAccount+claudeDefaults+"projects:\n  loupe:\n    dir: "+dir+"\n    work:\n      fix:\n        prompt: x\n"), Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if got := s.WorkKindsOn(); !slices.Equal(got, []string{"fix", "teardown"}) {
		t.Fatalf("WorkKindsOn = %v", got)
	}
}

func TestParseChecksEachProjectEntryAndNamesTheProject(t *testing.T) {
	for name, tc := range map[string]struct{ fields, want string }{
		"a command entry":    {"action: command\nrun: [make]", `project "loupe" work "x": action command is not allowed under a project`},
		"an unknown account": {"prompt: x\naccount: nope", `project "loupe" work "x"`},
		"a bad kind":         {"prompt: x", `project "loupe" work "Bad_Kind"`},
		"an unknown key":     {"prompt: x\nbogus: 1", "bogus"},
	} {
		t.Run(name, func(t *testing.T) {
			kind := "x"
			if name == "a bad kind" {
				kind = "Bad_Kind"
			}
			body := claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: " + t.TempDir() + "\n    work:\n      " + kind + ":\n        " +
				strings.ReplaceAll(tc.fields, "\n", "\n        ") + "\n"
			if _, err := Parse([]byte(body), Defaults{}); err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
		})
	}
}

func TestACommandEntryStaysSharedAndRunsInAProject(t *testing.T) {
	s, _, _ := twoProjects(t, "  test:\n    action: command\n    run: [make]\n")

	if m := s.MatchWork(requestFor(projectID, "test")); m.Skip != Run || m.Command == nil {
		t.Fatalf("match = %+v", m)
	}
	if got := s.CommandEntries(); len(got) != 1 || got[0].Name != "test" {
		t.Fatalf("CommandEntries = %+v", got)
	}
}

func TestACommandEntryUnderAProjectIsRefusedAlsoForTeardown(t *testing.T) {
	loupeDir, plansDir := t.TempDir(), t.TempDir()
	body := claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: " + loupeDir + "\n    work:\n      teardown:\n        action: command\n        run: [make]\n  plans:\n    dir: " + plansDir + "\nwork:\n  fix:\n    prompt: x\n"
	_, err := Parse([]byte(body), Defaults{})
	if err == nil || !strings.Contains(err.Error(), "not allowed under a project") {
		t.Fatalf("err = %v", err)
	}
}

func TestWarningsNameWhatEachProjectCannotRun(t *testing.T) {
	s, loupeDir, _ := twoProjects(t, "")

	got := s.Warnings()
	want := []string{
		"project loupe: work implement before runs bin/before.sh, which does not exist in " + loupeDir,
		"project plans: lists no work entry review, which another project lists; a request of this kind for it is skipped, and another bridge can take it",
	}
	if !slices.Equal(got, want) {
		t.Fatalf("Warnings =\n%s\nwant\n%s", strings.Join(got, "\n"), strings.Join(want, "\n"))
	}

	if err := os.MkdirAll(filepath.Join(loupeDir, "bin"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(loupeDir, "bin", "before.sh"), nil, 0o755); err != nil {
		t.Fatal(err)
	}
	if got := s.Warnings(); len(got) != 1 || !strings.Contains(got[0], "lists no work entry review") {
		t.Fatalf("Warnings after the file exists = %v", got)
	}
}

func TestWarningsCheckTheSharedEntryAgainstEachProjectThatUsesIt(t *testing.T) {
	loupeDir, plansDir := t.TempDir(), t.TempDir()
	body := claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: " + loupeDir + "\n    work:\n      implement:\n        prompt: x\n  plans:\n    dir: " + plansDir +
		"\nwork:\n  implement:\n    prompt: y\n    before:\n      run: [bin/before.sh]\n"
	s, err := Parse([]byte(body), Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	got := s.Warnings()
	if len(got) != 1 || !strings.HasPrefix(got[0], "project plans: work implement before runs bin/before.sh") {
		t.Fatalf("Warnings = %v", got)
	}
}

func TestWarningsSayAProjectEntryRunsWithoutTheVariants(t *testing.T) {
	dir := t.TempDir()
	body := claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: " + dir + "\n    work:\n      split:\n        prompt: x\n" +
		"experiments:\n  trial:\n    covers: [split]\n    baseline: a\n    variants:\n      - {name: a, weight: 1, model: opus}\n      - {name: b, weight: 1, model: sonnet}\n    metrics: [cost]\n" +
		"work:\n  split:\n    prompt: y\n"
	s, err := Parse([]byte(body), Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	got := s.Warnings()
	if len(got) != 1 || !strings.Contains(got[0], "runs without the variants") {
		t.Fatalf("Warnings = %v", got)
	}
}

func TestUsedAccountsReadsTheProjectEntries(t *testing.T) {
	dir := t.TempDir()
	body := "accounts:\n  claude:\n    harness: claude-code\n  other:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n  loupe:\n    dir: " + dir +
		"\n    work:\n      fix:\n        prompt: x\n        account: other\nwork:\n  implement:\n    prompt: y\n"
	s, err := Parse([]byte(body), Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if got := s.UsedAccounts(); !slices.Equal(got, []string{"claude", "other"}) {
		t.Fatalf("UsedAccounts = %v", got)
	}
}

// A project reads its own entry of a kind in place of the shared one, so the
// account of the replaced shared entry is not one it uses.
func TestUsedAccountsForReadsTheEffectiveEntriesOfOneProject(t *testing.T) {
	body := "accounts:\n  claude:\n    harness: claude-code\n  other:\n    harness: claude-code\n  third:\n    harness: claude-code\ndefaults:\n  account: claude\n" +
		"projects:\n  loupe:\n    dir: " + t.TempDir() + "\n    work:\n      fix:\n        prompt: x\n        account: other\n  plain:\n    dir: " + t.TempDir() +
		"\nwork:\n  implement:\n    prompt: y\n  fix:\n    prompt: z\n    account: third\n"
	s, err := Parse([]byte(body), Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	for slug, want := range map[string][]string{"loupe": {"claude", "other"}, "plain": {"claude", "third"}} {
		if got := s.UsedAccountsFor(slug); !slices.Equal(got, want) {
			t.Errorf("UsedAccountsFor(%q) = %v, want %v", slug, got, want)
		}
	}
}
