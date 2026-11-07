package profile

import (
	"os"
	"path/filepath"
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

func TestCheckMissingFileIsComplete(t *testing.T) {
	s, err := Check(t.TempDir())
	if err != nil || !s.Missing || !s.Complete() {
		t.Fatalf("Check = %+v, %v; want Missing and Complete", s, err)
	}
}

func TestCheckRequiresNoSectionAndNoValue(t *testing.T) {
	for name, body := range map[string]string{
		"empty":          "",
		"old open value": "## Gate\n\n1. Other actions: owner to fill in.\n",
		"old sections":   "# Lifecycle profile\n\n## Repair\n\n1. A value.\n",
	} {
		t.Run(name, func(t *testing.T) {
			dir := t.TempDir()
			write(t, dir, body)
			s, err := Check(dir)
			if err != nil || s.Missing || !s.Complete() {
				t.Fatalf("Check = %+v, %v; want complete", s, err)
			}
		})
	}
}
