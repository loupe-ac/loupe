package projectfile

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const validID = "01a0c0d9-905c-7922-a586-ccc8ce043704"

// write puts body in a fresh directory and returns that directory.
func write(t *testing.T, body string) string {
	t.Helper()
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, Name), []byte(body), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	return dir
}

func TestADirectoryWithNoFileReportsErrNoFile(t *testing.T) {
	if _, err := Load(t.TempDir()); !errors.Is(err, ErrNoFile) {
		t.Fatalf("Load of an empty directory: got %v, want ErrNoFile", err)
	}
}

func TestAFileNamesItsProject(t *testing.T) {
	file, err := Load(write(t, "project: "+validID+"\n"))
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != validID {
		t.Fatalf("Project: got %q, want %q", file.Project, validID)
	}
}

func TestAnUnknownKeyIsIgnoredSoALaterFormatStillReads(t *testing.T) {
	file, err := Load(write(t, "project: "+validID+"\nprojects:\n  ./api: "+validID+"\n"))
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != validID {
		t.Fatalf("Project: got %q, want %q", file.Project, validID)
	}
}

func TestAMalformedFileFailsAndNamesTheFile(t *testing.T) {
	_, err := Load(write(t, "project: [not, a, string\n"))
	if err == nil {
		t.Fatal("Load of a malformed file: got no error")
	}
	if !strings.Contains(err.Error(), Name) {
		t.Fatalf("error must name the file, got %v", err)
	}
}

func TestAProjectThatIsNotAnIdentifierIsRefused(t *testing.T) {
	_, err := Load(write(t, "project: my-board\n"))
	if err == nil {
		t.Fatal("Load of a non-identifier project: got no error")
	}
	if !strings.Contains(err.Error(), "my-board") {
		t.Fatalf("error must quote the value, got %v", err)
	}
}

func TestAnIdentifierInUpperCaseReadsAsLowerCase(t *testing.T) {
	file, err := Load(write(t, "project: "+strings.ToUpper(validID)+"\n"))
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != validID {
		t.Fatalf("Project: got %q, want %q", file.Project, validID)
	}
}

func TestAFileWithNoProjectKeyNamesNoProject(t *testing.T) {
	file, err := Load(write(t, "# nothing here yet\n"))
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != "" {
		t.Fatalf("Project: got %q, want the empty string", file.Project)
	}
}

func TestWriteThenLoadGivesBackTheProject(t *testing.T) {
	dir := t.TempDir()
	if err := Write(dir, strings.ToUpper(validID)); err != nil {
		t.Fatalf("Write: %v", err)
	}

	file, err := Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != validID {
		t.Fatalf("Project: got %q, want %q", file.Project, validID)
	}
}

func TestWriteRefusesAValueThatIsNotAnIdentifier(t *testing.T) {
	if err := Write(t.TempDir(), "my-board"); err == nil {
		t.Fatal("Write of a non-identifier: got no error")
	}
}

func TestValidAcceptsAnIdentifierInEitherCase(t *testing.T) {
	for _, id := range []string{validID, strings.ToUpper(validID)} {
		if err := Valid(id); err != nil {
			t.Fatalf("Valid(%q): got %v, want nil", id, err)
		}
	}
}

func TestValidRefusesAValueThatIsNotAnIdentifier(t *testing.T) {
	err := Valid("my-board")
	if err == nil {
		t.Fatal("Valid of a non-identifier: got no error")
	}
	if !strings.Contains(err.Error(), `"my-board"`) {
		t.Fatalf("error must quote the value, got %v", err)
	}
}

func TestAFileLargerThanTheCapIsRefused(t *testing.T) {
	_, err := Load(write(t, strings.Repeat("# padding\n", maxSize/10+16)))
	if err == nil {
		t.Fatal("Load of an oversized file: got no error")
	}
	if !strings.Contains(err.Error(), Name) {
		t.Fatalf("error must name the file, got %v", err)
	}
}

func readBack(t *testing.T, dir string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(dir, Name))
	if err != nil {
		t.Fatalf("read back: %v", err)
	}

	return string(b)
}

func assertNoTempFiles(t *testing.T, dir string) {
	t.Helper()
	entries, err := os.ReadDir(dir)
	if err != nil {
		t.Fatalf("read dir: %v", err)
	}
	if len(entries) != 1 || entries[0].Name() != Name {
		t.Fatalf("directory must hold only %s, got %v", Name, entries)
	}
}

func TestWriteKeepsOtherKeysOrderAndComments(t *testing.T) {
	dir := write(t, "# top note\nworkflow:\n  # inner note\n  review: true\nproject: 11111111-1111-4111-8111-111111111111 # old\nzeta: 1\n")
	if err := Write(dir, strings.ToUpper(validID)); err != nil {
		t.Fatalf("Write: %v", err)
	}
	got := readBack(t, dir)
	want := "# top note\nworkflow:\n  # inner note\n  review: true\nproject: " + validID + " # old\nzeta: 1\n"
	if got != want {
		t.Fatalf("got:\n%s\nwant:\n%s", got, want)
	}
	assertNoTempFiles(t, dir)
}

func TestWriteAddsTheProjectWhenMissing(t *testing.T) {
	dir := write(t, "# note\nworkflow:\n  review: true\n")
	if err := Write(dir, validID); err != nil {
		t.Fatalf("Write: %v", err)
	}
	got := readBack(t, dir)
	if got != "# note\nworkflow:\n  review: true\nproject: "+validID+"\n" {
		t.Fatalf("unexpected content:\n%s", got)
	}
	f, err := Load(dir)
	if err != nil || f.Project != validID {
		t.Fatalf("Load = %+v, %v", f, err)
	}
}

func TestWriteWithNoFileWritesTheFreshFile(t *testing.T) {
	dir := t.TempDir()
	if err := Write(dir, validID); err != nil {
		t.Fatalf("Write: %v", err)
	}
	want := "# The Loupe project this repository belongs to. `loupe mcp` sends it with\n" +
		"# every request, so an agent acts on this project and no other.\n" +
		"project: " + validID + "\n"
	if got := readBack(t, dir); got != want {
		t.Fatalf("got:\n%s", got)
	}
	assertNoTempFiles(t, dir)
}

func TestWriteRefusesAFileItCannotEditAndLeavesItAlone(t *testing.T) {
	cases := map[string]string{
		"garbage":      "project: [unclosed\n  : :\n",
		"oversized":    strings.Repeat("# padding\n", maxSize/10+16),
		"list root":    "- a\n- b\n",
		"scalar root":  "just text\n",
		"comment only": "# nothing here\n",
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			dir := write(t, body)
			err := Write(dir, validID)
			if err == nil {
				t.Fatal("Write: got no error")
			}
			if !strings.Contains(err.Error(), Name) {
				t.Fatalf("error must name the file, got %v", err)
			}
			if got := readBack(t, dir); got != body {
				t.Fatal("file must stay untouched")
			}
			assertNoTempFiles(t, dir)
		})
	}
}
