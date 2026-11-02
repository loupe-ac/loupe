package profile

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func put(t *testing.T, dir, name, body string) {
	t.Helper()
	path := filepath.Join(dir, name)
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
}

func runs(d Detection) []string {
	var out []string
	for _, c := range d.Gate {
		out = append(out, c.Run)
	}

	return out
}

func sameList(got, want []string) bool {
	return strings.Join(got, "|") == strings.Join(want, "|")
}

func TestAJustfileGivesTheGateInGateOrder(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "justfile", "test:\n    go test\n\nlint:\n    go vet\n\ndeploy env:\n    echo\n\nvar := \"x\"\n")

	if got := runs(Detect(dir)); !sameList(got, []string{"just lint", "just test"}) {
		t.Fatalf("gate: got %v", got)
	}
}

func TestATaskRunnerHidesTheManifests(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "justfile", "test:\n    x\n")
	put(t, dir, "package.json", `{"scripts":{"lint":"x"}}`)

	if got := runs(Detect(dir)); !sameList(got, []string{"just test"}) {
		t.Fatalf("gate: got %v", got)
	}
}

func TestAMakefileGivesTheGateWhenNoJustfileExists(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "Makefile", "CC := gcc\n\ntest: build\n\t./t\n\nbuild:\n\tcc\n")

	if got := runs(Detect(dir)); !sameList(got, []string{"make test"}) {
		t.Fatalf("gate: got %v", got)
	}
}

func TestPackageScriptsUseTheRunnerOfTheLockfile(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "package.json", `{"scripts":{"test":"vitest","lint":"eslint .","build":"vite build"}}`)
	put(t, dir, "pnpm-lock.yaml", "")

	if got := runs(Detect(dir)); !sameList(got, []string{"pnpm run lint", "pnpm run test"}) {
		t.Fatalf("gate: got %v", got)
	}
}

func TestNpmTestKeepsItsShortForm(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "package.json", `{"scripts":{"lint":"x","test":"y"}}`)

	if got := runs(Detect(dir)); !sameList(got, []string{"npm run lint", "npm test"}) {
		t.Fatalf("gate: got %v", got)
	}
}

func TestManifestsOfSeveralLanguagesAllContribute(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "package.json", `{"scripts":{"test":"y"}}`)
	put(t, dir, "composer.json", `{"scripts":{"phpstan":"x"}}`)
	put(t, dir, "go.mod", "module x\n")

	want := []string{"npm test", "composer run phpstan", "go vet ./...", "go test ./..."}
	if got := runs(Detect(dir)); !sameList(got, want) {
		t.Fatalf("gate: got %v, want %v", got, want)
	}
}

func TestABrokenManifestIsNoGate(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "package.json", `{not json`)

	if got := Detect(dir).Gate; len(got) != 0 {
		t.Fatalf("gate: got %v", got)
	}
}

func TestTheBaseBranchComesFromTheRecordedRemoteHead(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, ".git/refs/remotes/origin/HEAD", "ref: refs/remotes/origin/trunk\n")

	d := Detect(dir)
	if d.Base != "trunk" || !d.BaseFound {
		t.Fatalf("base: got %q found %v", d.Base, d.BaseFound)
	}
}

func TestWithoutARecordedRemoteHeadTheBaseIsMainAndGuessed(t *testing.T) {
	d := Detect(t.TempDir())
	if d.Base != "main" || d.BaseFound {
		t.Fatalf("base: got %q found %v", d.Base, d.BaseFound)
	}
}

func TestEveryStageSectionIsWritten(t *testing.T) {
	text := Render(Detect(t.TempDir()))
	for _, section := range []string{
		"Instruction files", "Environment", "Gate", "Code review", "Changelog",
		"Pull request", "Board", "Merge", "Epics", "Repair",
	} {
		if !strings.Contains(text, "\n## "+section+"\n") {
			t.Errorf("section %q is missing", section)
		}
	}
}

func TestEveryGuessedGateCommandCarriesTheMarker(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "justfile", "test:\n    x\n")

	text := Render(Detect(dir))
	if !strings.Contains(text, "Run `just test` ("+Marker+": justfile).") {
		t.Fatalf("gate line is not marked:\n%s", text)
	}
}

func TestADetectedChangelogAndInstructionFileAreNamed(t *testing.T) {
	dir := t.TempDir()
	put(t, dir, "CHANGELOG.md", "")
	put(t, dir, "AGENTS.md", "")

	text := Render(Detect(dir))
	for _, want := range []string{"`CHANGELOG.md`", "Read `AGENTS.md`"} {
		if !strings.Contains(text, want) {
			t.Errorf("missing %q", want)
		}
	}
}

func TestWriteCreatesTheProfileAndKeepsAnExistingOne(t *testing.T) {
	dir := t.TempDir()

	if got, err := Write(dir); err != nil || Written != got {
		t.Fatalf("first Write: got %v, %v", got, err)
	}
	path := filepath.Join(dir, Path)
	if err := os.WriteFile(path, []byte("mine\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	if got, err := Write(dir); err != nil || Existing != got {
		t.Fatalf("second Write: got %v, %v", got, err)
	}
	if body, _ := os.ReadFile(path); string(body) != "mine\n" {
		t.Fatalf("existing profile was replaced: %q", body)
	}
}

func TestTheGateSaysItCanMissACheck(t *testing.T) {
	for _, justfile := range []string{"", "test:\n    x\n\narkitect:\n    x\n"} {
		dir := t.TempDir()
		if "" != justfile {
			put(t, dir, "justfile", justfile)
		}

		text := Render(Detect(dir))
		gate := text[strings.Index(text, "## Gate"):strings.Index(text, "## Code review")]
		if !strings.Contains(gate, "this list can miss one ("+Marker+")") {
			t.Fatalf("gate does not say it is incomplete:\n%s", gate)
		}
		if strings.Count(gate, "\n3. ") != 1 {
			t.Fatalf("gate items are misnumbered:\n%s", gate)
		}
	}
}

func TestTheEpicBranchPatternIsMarked(t *testing.T) {
	text := Render(Detect(t.TempDir()))
	if !strings.Contains(text, "is `epic/<n>` ("+Marker+")") {
		t.Fatalf("epic branch pattern is missing or unmarked:\n%s", text)
	}
}

func TestNoPolicyIsStatedAsFact(t *testing.T) {
	text := Render(Detect(t.TempDir()))
	for _, policy := range []string{"squash", "<type>", "--admin", "--no-verify", "pre-dates", "largest change"} {
		if strings.Contains(text, policy) {
			t.Errorf("the starter states %q:\n%s", policy, text)
		}
	}
}

func TestEveryPolicyOfTheMergeIsAnOpenQuestion(t *testing.T) {
	text := Render(Detect(t.TempDir()))
	for _, want := range []string{
		"The merge method: " + OpenQuestion,
		"The merge method of an epic child: " + OpenQuestion,
		"Required checks: " + OpenQuestion,
	} {
		if !strings.Contains(text, want) {
			t.Errorf("missing %q:\n%s", want, text)
		}
	}
}

func TestEveryItemIsMarkedOrPointsAtASection(t *testing.T) {
	text := Render(Detect(t.TempDir()))
	for _, line := range strings.Split(text, "\n") {
		if "" == line || line[0] < '0' || line[0] > '9' {
			continue
		}
		if !strings.Contains(line, Marker) && !strings.Contains(line, OpenQuestion) && line != "4. An epic child merges as the `Epics` section says." {
			t.Errorf("unmarked item: %s", line)
		}
	}
}

func TestTheBoardNamesEverySlugThatAStageReads(t *testing.T) {
	text := Render(Detect(t.TempDir()))
	for _, want := range []string{"`in-review`", "`product-design`", "`tech-design`", "`implementation`", "`backlog`", "`done`"} {
		if !strings.Contains(text, want) {
			t.Errorf("missing %s", want)
		}
	}
}

func TestRepairAllowsAResetOnlyWithAResetCommand(t *testing.T) {
	text := Render(Detect(t.TempDir()))
	if !strings.Contains(text, "only when the `repair` work entry of the rule file sets `reset`") {
		t.Fatalf("the reset is not conditional:\n%s", text)
	}
	if !strings.Contains(text, "\"Sync the epic branch\"") {
		t.Fatalf("the epic sync is missing:\n%s", text)
	}
}
