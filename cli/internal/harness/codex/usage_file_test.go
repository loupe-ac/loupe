package codex

import (
	"os"
	"path/filepath"
	"testing"
)

func TestUsageFileFollowsTheThreadOfTheRun(t *testing.T) {
	home, dir := t.TempDir(), t.TempDir()
	h := New(home, "", "")

	if _, ok := h.UsageFile(dir); ok {
		t.Fatal("a run with no stdout has a file")
	}
	stdout := `{"type":"thread.started","thread_id":"01a11b44-0eb9-71f3-b3f1-3066d6b81ee6"}` + "\n"
	if err := os.WriteFile(filepath.Join(dir, "stdout"), []byte(stdout), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, ok := h.UsageFile(dir); ok {
		t.Fatal("a thread with no session file has a file")
	}
	sessions := filepath.Join(home, "sessions", "2026", "10", "20")
	if err := os.MkdirAll(sessions, 0o700); err != nil {
		t.Fatal(err)
	}
	want := filepath.Join(sessions, "rollout-2026-10-20T09-49-40-01a11b44-0eb9-71f3-b3f1-3066d6b81ee6.jsonl")
	if err := os.WriteFile(want, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	if got, ok := h.UsageFile(dir); !ok || got != want {
		t.Fatalf("UsageFile = %q, %v", got, ok)
	}
}
