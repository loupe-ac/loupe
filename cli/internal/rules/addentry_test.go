package rules

import (
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

const acceptingFile = `# The rule file of this machine.
acceptEntries: true
projects:
  loupe:
    dir: {dir}
work:
  # Plans a card.
  plan:
    prompt: Plan card {cardNumber}.
`

const newEntry = `export:
  action: command
  description: Export it
  params:
    out: {pattern: 'var/[a-z-]+', description: The folder}
  run: [bin/export, '{out}']
  timeout: 5m
`

func reasonOf(t *testing.T, err error) string {
	t.Helper()
	var refusal *EntryRefusal
	if !errors.As(err, &refusal) {
		t.Fatalf("error = %v, want an EntryRefusal", err)
	}

	return refusal.Reason
}

func TestAddWorkEntryAppendsTheEntryAndKeepsTheRest(t *testing.T) {
	path := writeRules(t, acceptingFile)

	if _, err := AddWorkEntry(path, newEntry, Defaults{}); err != nil {
		t.Fatal(err)
	}

	got := readFile(t, path)
	set, err := Parse([]byte(got), Defaults{})
	if err != nil {
		t.Fatalf("Parse: %v\n%s", err, got)
	}
	if !set.AcceptEntries() {
		t.Error("acceptEntries was lost")
	}
	entries := set.CommandEntries()
	if len(entries) != 1 || entries[0].Name != "export" || entries[0].Entry.Params["out"].Pattern != "var/[a-z-]+" || entries[0].Entry.Timeout != "5m" {
		t.Fatalf("entries = %+v\n%s", entries, got)
	}
	if !strings.Contains(got, "plan:") || !strings.Contains(got, "Plan card {cardNumber}.") {
		t.Fatalf("the plan entry is gone:\n%s", got)
	}
	if runtime.GOOS != "windows" {
		if info, err := os.Stat(path); err != nil || info.Mode().Perm() != 0o640 {
			t.Fatalf("mode = %v, %v", info.Mode(), err)
		}
	}
}

func TestAddWorkEntryCreatesTheWorkMap(t *testing.T) {
	for name, body := range map[string]string{
		"absent": "acceptEntries: true\nprojects:\n  loupe:\n    dir: {dir}\n",
		"null":   "acceptEntries: true\nprojects:\n  loupe:\n    dir: {dir}\nwork:\n",
		"empty":  "acceptEntries: true\nprojects:\n  loupe:\n    dir: {dir}\nwork: {}\n",
	} {
		t.Run(name, func(t *testing.T) {
			path := writeRules(t, body)

			if _, err := AddWorkEntry(path, newEntry, Defaults{}); err != nil {
				t.Fatal(err)
			}

			got := readFile(t, path)
			set, err := Parse([]byte(got), Defaults{})
			if err != nil || len(set.CommandEntries()) != 1 {
				t.Fatalf("Parse: %v\n%s", err, got)
			}
		})
	}
}

func TestAddWorkEntryRefusesAndLeavesTheFileAlone(t *testing.T) {
	off := strings.Replace(acceptingFile, "acceptEntries: true\n", "", 1)
	explicitOff := strings.Replace(acceptingFile, "acceptEntries: true", "acceptEntries: false", 1)
	for name, tc := range map[string]struct {
		file, entry, reason string
	}{
		"not accepted":        {off, newEntry, RefuseNotAccepted},
		"accept off":          {explicitOff, newEntry, RefuseNotAccepted},
		"name taken":          {acceptingFile, "plan:\n  action: command\n  run: [true]\n", RefuseEntryExists},
		"not yaml":            {acceptingFile, "export: [unclosed\n", RefuseEntryInvalid},
		"empty":               {acceptingFile, "", RefuseEntryInvalid},
		"not a mapping":       {acceptingFile, "- export\n", RefuseEntryInvalid},
		"two keys":            {acceptingFile, "a:\n  action: command\n  run: [true]\nb:\n  action: command\n  run: [true]\n", RefuseEntryInvalid},
		"two documents":       {acceptingFile, newEntry + "---\n" + newEntry, RefuseEntryInvalid},
		"body not a mapping":  {acceptingFile, "export: [bin/export]\n", RefuseEntryInvalid},
		"agent entry":         {acceptingFile, "export:\n  prompt: Do it.\n", RefuseEntryInvalid},
		"interactive entry":   {acceptingFile, "export:\n  action: interactive\n  prompt: Do it.\n", RefuseEntryInvalid},
		"param no pattern":    {acceptingFile, "export:\n  action: command\n  params:\n    out: {description: x}\n  run: [bin/export, '{out}']\n", RefuseEntryInvalid},
		"lookahead pattern":   {acceptingFile, "export:\n  action: command\n  params:\n    out: {pattern: '(?=a)a'}\n  run: [bin/export, '{out}']\n", RefuseEntryInvalid},
		"reserved param name": {acceptingFile, "export:\n  action: command\n  params:\n    kind: {pattern: 'a'}\n  run: [bin/export, '{kind}']\n", RefuseEntryInvalid},
		"unknown key":         {acceptingFile, "export:\n  action: command\n  bogus: 1\n  run: [true]\n", RefuseEntryInvalid},
		"no run":              {acceptingFile, "export:\n  action: command\n", RefuseEntryInvalid},
	} {
		t.Run(name, func(t *testing.T) {
			path := writeRules(t, tc.file)
			before := readFile(t, path)

			_, err := AddWorkEntry(path, tc.entry, Defaults{})

			if got := reasonOf(t, err); got != tc.reason {
				t.Fatalf("reason = %s (%v), want %s", got, err, tc.reason)
			}
			if after := readFile(t, path); after != before {
				t.Fatalf("the file changed:\n%s", after)
			}
		})
	}
}

func TestAddWorkEntryRefusesAMissingFile(t *testing.T) {
	_, err := AddWorkEntry(t.TempDir()+"/rules.yaml", newEntry, Defaults{})

	if got := reasonOf(t, err); got != RefuseWriteFailed {
		t.Fatalf("reason = %s (%v)", got, err)
	}
}

// An edit made while the bridge parses the file stays, and the add refuses.
func TestAddWorkEntryRefusesWhenTheFileChangesMeanwhile(t *testing.T) {
	path := writeRules(t, acceptingFile)
	edited := acceptingFile + "# An edit of the operator.\n"
	reads := 0
	readRuleFile = func(name string) ([]byte, error) {
		reads++
		if reads == 2 {
			if err := os.WriteFile(name, []byte(edited), 0o640); err != nil {
				t.Fatal(err)
			}
		}

		return os.ReadFile(name)
	}
	t.Cleanup(func() { readRuleFile = os.ReadFile })

	_, err := AddWorkEntry(path, newEntry, Defaults{})

	if got := reasonOf(t, err); got != RefuseFileChanged {
		t.Fatalf("reason = %s (%v), want %s", got, err, RefuseFileChanged)
	}
	if after := readFile(t, path); after != edited {
		t.Fatalf("the edit was lost:\n%s", after)
	}
	if left, _ := filepath.Glob(filepath.Join(filepath.Dir(path), ".*-*")); len(left) != 0 {
		t.Fatalf("temporary files left = %v", left)
	}
}

// The check that the file is unchanged runs after the new bytes are ready,
// just before the rename, so an edit made while the bridge encodes stays.
func TestAddWorkEntryChecksTheFileJustBeforeTheRename(t *testing.T) {
	path := writeRules(t, acceptingFile)
	edited := acceptingFile + "# An edit of the operator.\n"
	reads := 0
	readRuleFile = func(name string) ([]byte, error) {
		reads++
		if reads == 2 {
			left, _ := filepath.Glob(filepath.Join(filepath.Dir(name), ".*-*"))
			if len(left) != 1 {
				t.Errorf("the check ran before the new bytes were ready: temporary files = %v", left)
			}
			if err := os.WriteFile(name, []byte(edited), 0o640); err != nil {
				t.Fatal(err)
			}
		}

		return os.ReadFile(name)
	}
	t.Cleanup(func() { readRuleFile = os.ReadFile })

	_, err := AddWorkEntry(path, newEntry, Defaults{})

	if got := reasonOf(t, err); got != RefuseFileChanged {
		t.Fatalf("reason = %s (%v), want %s", got, err, RefuseFileChanged)
	}
	if after := readFile(t, path); after != edited {
		t.Fatalf("the edit was lost:\n%s", after)
	}
}

// A retry of an add that wrote the file finds the same entry, whatever its
// style, and changes nothing.
func TestAddWorkEntryReportsTheSameEntryAsPresent(t *testing.T) {
	path := writeRules(t, acceptingFile)
	if _, err := AddWorkEntry(path, newEntry, Defaults{}); err != nil {
		t.Fatal(err)
	}
	before := readFile(t, path)

	for name, text := range map[string]string{
		"same text":  newEntry,
		"flow style": "export: {action: command, description: Export it, params: {out: {pattern: 'var/[a-z-]+', description: The folder}}, run: [bin/export, '{out}'], timeout: 5m}\n",
	} {
		t.Run(name, func(t *testing.T) {
			undo, err := AddWorkEntry(path, text, Defaults{})

			if !errors.Is(err, ErrEntryPresent) || undo != nil {
				t.Fatalf("err = %v, want ErrEntryPresent and no undo", err)
			}
			if after := readFile(t, path); after != before {
				t.Fatalf("the file changed:\n%s", after)
			}
		})
	}
}

// An entry of the same name with another body is still refused.
func TestAddWorkEntryRefusesTheSameNameWithAnotherBody(t *testing.T) {
	path := writeRules(t, acceptingFile)
	if _, err := AddWorkEntry(path, newEntry, Defaults{}); err != nil {
		t.Fatal(err)
	}

	_, err := AddWorkEntry(path, strings.Replace(newEntry, "timeout: 5m", "timeout: 6m", 1), Defaults{})

	if got := reasonOf(t, err); got != RefuseEntryExists {
		t.Fatalf("reason = %s (%v), want %s", got, err, RefuseEntryExists)
	}
}

// Undo puts the old bytes back.
func TestAddWorkEntryUndoRestoresTheFile(t *testing.T) {
	path := writeRules(t, acceptingFile)
	before := readFile(t, path)

	undo, err := AddWorkEntry(path, newEntry, Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := undo(); err != nil {
		t.Fatal(err)
	}

	if after := readFile(t, path); after != before {
		t.Fatalf("the file was not restored:\n%s", after)
	}
}

// Undo keeps an edit made after the add.
func TestAddWorkEntryUndoKeepsALaterEdit(t *testing.T) {
	path := writeRules(t, acceptingFile)

	undo, err := AddWorkEntry(path, newEntry, Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	edited := readFile(t, path) + "# A later edit.\n"
	if err := os.WriteFile(path, []byte(edited), 0o640); err != nil {
		t.Fatal(err)
	}

	if err := undo(); err == nil {
		t.Fatal("undo replaced a file that changed after the add")
	}
	if after := readFile(t, path); after != edited {
		t.Fatalf("the later edit was lost:\n%s", after)
	}
}
