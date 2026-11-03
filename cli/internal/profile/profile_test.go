package profile

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func write(t *testing.T, dir, body string) {
	t.Helper()
	path := filepath.Join(dir, Path)
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
}

func full() string {
	var b strings.Builder
	b.WriteString("# Lifecycle profile\n\n")
	for _, name := range Sections {
		b.WriteString("## " + name + "\n\n1. A value.\n\n")
	}

	return b.String()
}

func TestCheckMissingFile(t *testing.T) {
	s, err := Check(t.TempDir())
	if err != nil || !s.Missing || s.Complete() {
		t.Fatalf("Check = %+v, %v; want Missing", s, err)
	}
}

func TestCheckCompleteFile(t *testing.T) {
	dir := t.TempDir()
	write(t, dir, full())
	s, err := Check(dir)
	if err != nil || !s.Complete() {
		t.Fatalf("Check = %+v, %v; want complete", s, err)
	}
}

func TestCheckMissingSection(t *testing.T) {
	dir := t.TempDir()
	write(t, dir, strings.Replace(full(), "## Repair", "## Other", 1))
	s, _ := Check(dir)
	if len(s.MissingSections) != 1 || s.MissingSections[0] != "Repair" || s.Complete() {
		t.Fatalf("Check = %+v; want Repair missing", s)
	}
	if !strings.Contains(s.Reason(), "Repair") {
		t.Fatalf("Reason = %q", s.Reason())
	}
}

func TestCheckOpenValue(t *testing.T) {
	dir := t.TempDir()
	write(t, dir, full()+"4. Other actions: "+OpenQuestion+".\n")
	s, _ := Check(dir)
	if s.OpenValues != 1 || s.Complete() {
		t.Fatalf("Check = %+v; want one open value", s)
	}
}

func TestCheckIgnoresTheOldGuessMarker(t *testing.T) {
	dir := t.TempDir()
	write(t, dir, full()+"1. Run `make test` (detected, check it).\n")
	s, _ := Check(dir)
	if !s.Complete() {
		t.Fatalf("Check = %+v; an old marker must not start the agent", s)
	}
}
