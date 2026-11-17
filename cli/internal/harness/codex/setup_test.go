package codex

import (
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/harness"
)

func TestSessionArgsCarryTheSetupOnlyOutsideTheDefault(t *testing.T) {
	t.Cleanup(func() { _, _ = config.Resolve("") })
	spec := harness.Spec{SessionID: runID}

	_, _ = config.Resolve("")
	if got := sessionArgs(spec); len(got) != 2 {
		t.Fatalf("default setup args = %q", got)
	}
	if got := sessionArgs(harness.Spec{}); got != nil {
		t.Fatalf("no session, default setup: %q", got)
	}

	_, _ = config.Resolve("/tmp/my setup")
	want := []string{
		"-c", "mcp_servers.loupe.env.LOUPE_SESSION_ID=\"" + runID + "\"",
		"-c", "mcp_servers.loupe.env.LOUPE_CONFIG=\"/tmp/my setup\"",
	}
	if got := sessionArgs(spec); !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
	if got := sessionArgs(harness.Spec{}); !slices.Equal(got, want[2:]) {
		t.Fatalf("no session: %q", got)
	}

	body := New("", "", "").Interactive("/bin/codex", spec)
	if !strings.Contains(body, "'mcp_servers.loupe.env.LOUPE_CONFIG=\"/tmp/my setup\"'") {
		t.Fatalf("interactive script lacks the setup:\n%s", body)
	}
	cmd := New("", "", "").Worker(harness.Spec{Dir: t.TempDir(), SessionID: runID, Prompt: "x"})
	if !slices.Contains(cmd.Args, "mcp_servers.loupe.env.LOUPE_CONFIG=\"/tmp/my setup\"") {
		t.Fatalf("worker args lack the setup: %q", cmd.Args)
	}
}

func TestSessionArgsForwardTheRunCredentialByName(t *testing.T) {
	t.Cleanup(func() { _, _ = config.Resolve("") })
	_, _ = config.Resolve("")

	got := sessionArgs(harness.Spec{Env: []string{"LOUPE_RUN_TOKEN=secret", "LOUPE_URL=https://x", "LOUPE_PROJECT=p"}})
	want := []string{"-c", `mcp_servers.loupe.env_vars=["LOUPE_RUN_TOKEN","LOUPE_URL","LOUPE_PROJECT"]`}
	if !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
	for _, a := range got {
		if strings.Contains(a, "secret") {
			t.Fatalf("the token is on the command line: %q", got)
		}
	}
	if got := sessionArgs(harness.Spec{Env: []string{"A=1"}}); got != nil {
		t.Fatalf("no token: %q", got)
	}
}
