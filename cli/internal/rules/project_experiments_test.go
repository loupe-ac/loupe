package rules

import (
	"slices"
	"strings"
	"testing"
)

const projectTrialVariants = "        variants:\n          - {name: a, weight: 1, model: opus}\n          - {name: b, weight: 1, model: sonnet}\n"

// projectTrial is a project experiments block of the given name and covers.
func projectTrial(name, covers string) string {
	return "    experiments:\n      " + name + ":\n        covers: " + covers + "\n        baseline: a\n" + projectTrialVariants
}

// projectExperimentFile has loupe list its own fix entry, and plans use the
// shared one.
func projectExperimentFile(loupeBlock, tail string) string {
	return claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\n    work:\n      fix:\n        prompt: Loupe fix.\n" + loupeBlock +
		"  plans:\n    dir: {dir}\nwork:\n  fix:\n    prompt: Shared fix.\n" + tail
}

// checkedBoth is checked for a file that maps loupe and plans.
func checkedBoth(t *testing.T, body string) *Set {
	t.Helper()
	s := parse(t, body)
	if err := s.Check(t.Context(), columnsServer(t, map[string]string{"loupe": loupeColumns, "plans": otherColumns})); err != nil {
		t.Fatal(err)
	}

	return s
}

func TestAProjectExperimentCoversTheEntryTheProjectLists(t *testing.T) {
	s := checkedBoth(t, projectExperimentFile(projectTrial("fix-2", "[fix]"), ""))

	m := s.MatchWork(workRequest("fix"))
	if m.Skip != Run || !strings.HasPrefix(m.Prompt, "Loupe fix") || m.Experiment == nil || m.Experiment.Name != "fix-2" {
		t.Fatalf("match = %+v", m)
	}
	if got := m.ApplyVariant(m.Experiment.Variants[1]).Model; got != "sonnet" {
		t.Fatalf("variant b model = %q", got)
	}
}

func TestAProjectExperimentCoversTheSharedEntryForThatProjectOnly(t *testing.T) {
	body := claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\n" + projectTrial("fix-2", "[fix]") +
		"  plans:\n    dir: {dir}\nwork:\n  fix:\n    prompt: Shared fix.\n"
	s := parse(t, body)

	if e, ok := s.entryFor("loupe", "fix"); !ok || e.experiment == nil || e.experiment.Name != "fix-2" || e.Subject == "" {
		t.Fatalf("loupe entry = %+v, %v", e, ok)
	}
	if e, _ := s.entryFor("plans", "fix"); e.experiment != nil {
		t.Fatalf("plans entry has experiment %q", e.experiment.Name)
	}
	if e := s.work["fix"]; e.experiment != nil {
		t.Fatalf("the shared entry has experiment %q", e.experiment.Name)
	}
	if got := s.CoveredWorkKinds("loupe"); !slices.Equal(got, []string{"fix"}) {
		t.Fatalf("CoveredWorkKinds = %v", got)
	}
	if got := s.ProjectWorkKinds("loupe"); len(got) != 0 {
		t.Fatalf("ProjectWorkKinds = %v, the copy must not read as an entry the owner wrote", got)
	}
}

func TestAProjectExperimentLeavesTheTopLevelOneForThatProject(t *testing.T) {
	tail := "experiments:\n  fix-1:\n    covers: [fix]\n    baseline: a\n    variants:\n      - {name: a, weight: 1}\n"
	s := parse(t, projectExperimentFile(projectTrial("fix-2", "[fix]"), tail))

	if e, _ := s.entryFor("loupe", "fix"); e.experiment == nil || e.experiment.Name != "fix-2" {
		t.Fatalf("loupe experiment = %+v", e.experiment)
	}
	if e, _ := s.entryFor("plans", "fix"); e.experiment == nil || e.experiment.Name != "fix-1" {
		t.Fatalf("plans experiment = %+v", e.experiment)
	}
	got := s.Warnings()
	want := `project loupe: experiment "fix-2" covers fix for this project, so its runs leave the top-level experiment "fix-1"`
	if !slices.Contains(got, want) {
		t.Fatalf("Warnings = %v, want %q", got, want)
	}
}

func TestAProjectEntryWithNoProjectExperimentWarnsAndNamesTheFix(t *testing.T) {
	tail := "experiments:\n  fix-1:\n    covers: [fix]\n    baseline: a\n    variants:\n      - {name: a, weight: 1}\n"
	s := parse(t, projectExperimentFile("", tail))

	got := s.Warnings()
	if len(got) != 1 || !strings.Contains(got[0], "add an experiments: block under the project") || !strings.Contains(got[0], `"fix-1"`) {
		t.Fatalf("Warnings = %v", got)
	}
}

func TestAProjectExperimentIsCheckedLikeATopLevelOne(t *testing.T) {
	for name, tc := range map[string]struct{ block, want string }{
		"a kind with no entry": {projectTrial("t", "[review]"), `project "loupe": experiment "t": covers kind "review", and work has no entry for it`},
		"an empty covers":      {projectTrial("t", "[]"), `project "loupe": experiment "t": covers lists no kind`},
		"a bad name":           {projectTrial("Bad_Name", "[fix]"), `project "loupe": experiment "Bad_Name"`},
		"the old repair kind":  {projectTrial("t", "[repair]"), `project "loupe": experiment "t": covers kind "repair", and work has no entry for it`},
	} {
		t.Run(name, func(t *testing.T) {
			text := projectExperimentFile(tc.block, "")
			text = strings.Replace(text, "  fix:\n    prompt: Shared fix.\n", "  fix:\n    prompt: Shared fix.\n  recover:\n    prompt: Recover.\n", 1)
			wantErr(t, text, tc.want)
		})
	}
}

func TestAProjectExperimentNameThatATopLevelOneHasIsRefused(t *testing.T) {
	tail := "experiments:\n  fix-2:\n    covers: [fix]\n    baseline: a\n    variants:\n      - {name: a, weight: 1}\n"
	wantErr(t, projectExperimentFile(projectTrial("fix-2", "[fix]"), tail), `project "loupe": experiment "fix-2": the top-level block also names an experiment "fix-2"`)
}

func TestTwoProjectsMayShareAnExperimentName(t *testing.T) {
	text := projectExperimentFile(projectTrial("fix-2", "[fix]"), "")
	text = strings.Replace(text, "  plans:\n    dir: {dir}\n", "  plans:\n    dir: {dir}\n"+projectTrial("fix-2", "[fix]"), 1)
	s := parse(t, text)

	if e, _ := s.entryFor("plans", "fix"); e.experiment == nil || e.experiment.Name != "fix-2" {
		t.Fatalf("plans experiment = %+v", e.experiment)
	}
}

func TestAProjectVariantAccountErrorNamesTheProjectBlock(t *testing.T) {
	block := strings.Replace(projectTrial("fix-2", "[fix]"), "{name: b, weight: 1, model: sonnet}", "{name: b, weight: 1, account: nope}", 1)
	wantErr(t, projectExperimentFile(block, ""), `project "loupe": experiment "fix-2": variant "b": account`)
}

func TestAProjectVariantAccountShowsInTheAccountListOfTheProject(t *testing.T) {
	body := "accounts:\n  claude:\n    harness: claude-code\n  other:\n    harness: claude-code\n" + claudeDefaults +
		"projects:\n  loupe:\n    dir: {dir}\n    experiments:\n      fix-2:\n        covers: [fix]\n        baseline: a\n        variants:\n          - {name: a, weight: 1}\n          - {name: b, weight: 1, account: other}\n" +
		"  plans:\n    dir: {dir}\nwork:\n  fix:\n    prompt: Shared fix.\n"
	s := parse(t, body)

	if got := s.UsedAccountsFor("loupe"); !slices.Equal(got, []string{"claude", "other"}) {
		t.Fatalf("loupe accounts = %v", got)
	}
	if got := s.UsedAccountsFor("plans"); !slices.Equal(got, []string{"claude"}) {
		t.Fatalf("plans accounts = %v", got)
	}
}

func TestAVariantMergesWithTheProjectEntry(t *testing.T) {
	s := checkedBoth(t, projectExperimentFile(projectTrial("fix-2", "[fix]"), ""))
	e, _ := s.entryFor("loupe", "fix")
	if e.Prompt != "Loupe fix." {
		t.Fatalf("prompt = %q", e.Prompt)
	}
}

// A project with only covered copies has no entry of its own, and the
// bridge-wide lists still read the variant accounts of its copies.
func TestTheBridgeWideListsReadACoveredCopyOfAProjectWithNoEntries(t *testing.T) {
	body := claudeAccount + "  other:\n    harness: claude-code\n" + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\n" +
		"    experiments:\n      fix-2:\n        covers: [fix]\n        baseline: a\n        variants:\n          - {name: a, weight: 1}\n          - {name: b, weight: 1, account: other}\n" +
		"work:\n  fix:\n    prompt: Shared fix.\n"
	s := parse(t, body)

	if got := s.UsedAccounts(); !slices.Contains(got, "other") {
		t.Fatalf("UsedAccounts = %v", got)
	}
	if got := s.AccountKinds("other"); !slices.Equal(got, []string{"fix"}) {
		t.Fatalf("AccountKinds = %v", got)
	}
	if got := len(s.entriesOf("fix")); got != 2 {
		t.Fatalf("entriesOf(fix) = %d entries, want the shared one and the copy", got)
	}
}
