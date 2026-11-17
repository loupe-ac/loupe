package update

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLoadStateOfAMissingFileIsEmpty(t *testing.T) {
	s, err := LoadState(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	if s.Skipped("1.3.0") || len(s.Staged) != 0 {
		t.Fatalf("state = %+v", s)
	}
}

func TestStateSurvivesASaveAndALoad(t *testing.T) {
	dir := t.TempDir()
	s, _ := LoadState(dir)
	s.Skip("1.3.0")
	s.Skip("1.3.0")
	s.Staged["1.4.0"] = StagePath(dir, "1.4.0")
	if err := s.Save(dir); err != nil {
		t.Fatal(err)
	}

	raw, _ := os.ReadFile(filepath.Join(dir, "update.json"))
	want := `{"skip":["1.3.0"],"staged":{"1.4.0":"` + filepath.Join(dir, "versions", "1.4.0", "loupe") + `"}}`
	if string(raw) != want {
		t.Fatalf("update.json = %s, want %s", raw, want)
	}
	got, err := LoadState(dir)
	if err != nil {
		t.Fatal(err)
	}
	if !got.Skipped("1.3.0") || got.Skipped("1.4.0") || got.Staged["1.4.0"] != StagePath(dir, "1.4.0") || !got.SkipSet()["1.3.0"] {
		t.Fatalf("state = %+v", got)
	}
}

func TestLoadStateRefusesABrokenFile(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "update.json"), []byte("{"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadState(dir); err == nil {
		t.Fatal("a broken file loaded")
	}
}

func TestRevokedKeysOnlyGrow(t *testing.T) {
	dir := t.TempDir()
	grew, err := AddRevoked(dir, []string{"SHA256:a", "SHA256:b", "SHA256:a", ""})
	if err != nil || !grew {
		t.Fatalf("first add: grew = %v, err = %v", grew, err)
	}
	if grew, _ := AddRevoked(dir, []string{"SHA256:b"}); grew {
		t.Fatal("a known key grew the set")
	}
	if grew, _ := AddRevoked(dir, nil); grew {
		t.Fatal("an empty list grew the set")
	}
	if got := LoadRevoked(dir); len(got) != 2 || got[0] != "SHA256:a" || got[1] != "SHA256:b" {
		t.Fatalf("revoked = %v", got)
	}
}

func TestSaveKeepsTheRevokedKeysOfTheFile(t *testing.T) {
	dir := t.TempDir()
	stale, _ := LoadState(dir)
	if _, err := AddRevoked(dir, []string{"SHA256:a"}); err != nil {
		t.Fatal(err)
	}
	stale.Skip("1.3.0")
	if err := stale.Save(dir); err != nil {
		t.Fatal(err)
	}
	got, _ := LoadState(dir)
	if len(got.Revoked) != 1 || got.Revoked[0] != "SHA256:a" || !got.Skipped("1.3.0") {
		t.Fatalf("state = %+v", got)
	}
}
