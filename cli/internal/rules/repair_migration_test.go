package rules

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// recoverSet parses a two project file. Both entries sit where the test puts
// them: the shared map, the loupe map, or both.
func recoverSet(t *testing.T, shared, loupe string) *Set {
	t.Helper()
	body := claudeAccount + claudeDefaults + "\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\n" + loupe +
		"  plans:\n    dir: " + t.TempDir() + "\nwork:\n  implement:\n    prompt: Implement {cardNumber}.\n" + shared
	s, err := Parse([]byte(body), Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := s.Check(t.Context(), columnsServer(t, map[string]string{"loupe": loupeColumns, "plans": otherColumns})); err != nil {
		t.Fatal(err)
	}

	return s
}

func TestEachKindRunsTheRenamedSharedEntry(t *testing.T) {
	s := recoverSet(t, "  recover:\n    prompt: Recover {cardNumber}.\n", "")

	for _, kind := range []string{"recover", "repair"} {
		for _, project := range []string{projectID, otherProjectID} {
			m := s.MatchWork(requestFor(project, kind))
			if m.Skip != Run || m.Rule != "work:"+kind || !strings.HasPrefix(m.Prompt, "Recover") {
				t.Fatalf("%s for %s = %+v", kind, project, m)
			}
		}
	}
	if got := s.WorkKindsOn(); !slices.Contains(got, "recover") || !slices.Contains(got, "repair") {
		t.Fatalf("WorkKindsOn = %v", got)
	}
	if got := s.WorkKinds(); slices.Contains(got, "repair") {
		t.Fatalf("WorkKinds = %v", got)
	}
}

func TestEachKindRunsTheRenamedProjectEntryForThatProjectOnly(t *testing.T) {
	s := recoverSet(t, "", "    work:\n      recover:\n        prompt: Loupe recover {cardNumber}.\n")

	for _, kind := range []string{"recover", "repair"} {
		if m := s.MatchWork(requestFor(projectID, kind)); m.Skip != Run || !strings.HasPrefix(m.Prompt, "Loupe recover") {
			t.Fatalf("%s for loupe = %+v", kind, m)
		}
		if m := s.MatchWork(requestFor(otherProjectID, kind)); m.Skip != NoRule {
			t.Fatalf("%s for plans = %+v", kind, m)
		}
	}
	if got := s.WorkKindsOn(); !slices.Contains(got, "repair") {
		t.Fatalf("WorkKindsOn = %v", got)
	}
}

func TestNoRecoverEntryAdvertisesNoRepairKind(t *testing.T) {
	s := recoverSet(t, "", "")

	if got := s.WorkKindsOn(); slices.Contains(got, "repair") || slices.Contains(got, "recover") {
		t.Fatalf("WorkKindsOn = %v", got)
	}
}

func TestLoadRefusesTheOldKeyInEachMapAndNamesTheNewKey(t *testing.T) {
	for name, body := range map[string]string{
		"shared":  claudeAccount + claudeDefaults + "\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\nwork:\n  repair:\n    prompt: Repair.\n",
		"project": claudeAccount + claudeDefaults + "\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\n    work:\n      repair:\n        prompt: Repair.\n",
	} {
		_, err := Parse([]byte(body), Defaults{})
		if err == nil || !strings.Contains(err.Error(), `"repair" is renamed to "recover"`) {
			t.Fatalf("%s: err = %v", name, err)
		}
	}
}

func migrateRepair(t *testing.T, body string) (bool, string, error) {
	t.Helper()
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte(body), 0o640); err != nil {
		t.Fatal(err)
	}
	changed, err := MigrateRepairKey(path)
	data, rerr := os.ReadFile(path)
	if rerr != nil {
		t.Fatal(rerr)
	}

	return changed, string(data), err
}

const repairRules = `# my rules
projects:
  loupe:
    dir: /tmp/loupe
    work:
      repair:   # git only
        prompt: Loupe repair.
  plans:
    dir: /tmp/plans
work:
  implement:
    prompt: Implement.
  repair:
    prompt: Repair.
experiments:
  cheap:
    covers: [implement]
  fixes:
    covers:
      - repair
`

func TestMigrateRepairKeyRenamesTheKeyInEachMapAndTheCovers(t *testing.T) {
	changed, after, err := migrateRepair(t, repairRules)
	if err != nil || !changed {
		t.Fatalf("changed = %v, err = %v", changed, err)
	}
	want := strings.NewReplacer("      repair:   #", "      recover:   #", "  repair:\n    prompt: Repair", "  recover:\n    prompt: Repair", "      - repair", "      - recover").Replace(repairRules)
	if after != want {
		t.Fatalf("file =\n%s", after)
	}
	if changed, _, err := migrateRepair(t, after); err != nil || changed {
		t.Fatalf("second run changed = %v, err = %v", changed, err)
	}
}

func TestMigrateRepairKeyLeavesAFileWithBothKeys(t *testing.T) {
	body := "work:\n  repair:\n    prompt: A.\n  recover:\n    prompt: B.\n"
	changed, after, err := migrateRepair(t, body)
	if changed || after != body || err == nil || !strings.Contains(err.Error(), "holds both") {
		t.Fatalf("changed = %v, err = %v, file = %q", changed, err, after)
	}
}

func TestMigrateRepairKeyLeavesAFileWithNoRepairKey(t *testing.T) {
	body := "work:\n  implement:\n    prompt: A.\n"
	if changed, after, err := migrateRepair(t, body); changed || err != nil || after != body {
		t.Fatalf("changed = %v, err = %v, file = %q", changed, err, after)
	}
}

func TestMigrateRepairKeyRefusesAQuotedKey(t *testing.T) {
	body := "work:\n  \"repair\":\n    prompt: A.\n"
	changed, after, err := migrateRepair(t, body)
	if changed || after != body || err == nil {
		t.Fatalf("changed = %v, err = %v, file = %q", changed, err, after)
	}
}
