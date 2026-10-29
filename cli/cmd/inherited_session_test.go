package cmd

import (
	"os"
	"slices"
	"strings"
	"testing"
)

func TestDropInheritedSessionKeepsNoSessionMarker(t *testing.T) {
	for _, name := range inheritedSessionEnv {
		t.Setenv(name, "outer")
	}
	t.Setenv("CLAUDE_CONFIG_DIR", "/home/a/.claude")
	t.Setenv("CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS", "5")

	dropInheritedSession()

	for _, e := range workerEnv(os.Environ(), "s1", "", nil, nil) {
		name, _, _ := strings.Cut(e, "=")
		if name == sessionEnv {
			continue
		}
		if slices.Contains(inheritedSessionEnv, name) {
			t.Errorf("worker environment holds %s", e)
		}
	}
	for _, name := range []string{"CLAUDE_CONFIG_DIR", "CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS"} {
		if os.Getenv(name) == "" {
			t.Errorf("%s was removed", name)
		}
	}
}
