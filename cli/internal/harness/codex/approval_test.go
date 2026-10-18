package codex

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func writeConfig(t *testing.T, body string) string {
	t.Helper()
	home := t.TempDir()
	if err := os.WriteFile(filepath.Join(home, "config.toml"), []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	return home
}

func readConfig(t *testing.T, home string) string {
	t.Helper()
	raw, err := os.ReadFile(filepath.Join(home, "config.toml"))
	if err != nil {
		t.Fatal(err)
	}

	return string(raw)
}

func TestApproveToolsAddsTheModeAndKeepsTheRest(t *testing.T) {
	home := writeConfig(t, "# mine\nmodel = \"o3\"\n\n[mcp_servers.loupe]\ncommand = \"loupe\"\nargs = [\"mcp\"]\n\n[mcp_servers.other]\ncommand = \"x\"\n")

	changed, err := ApproveTools(home)
	if err != nil || !changed {
		t.Fatalf("ApproveTools: %v, %v", changed, err)
	}
	want := "# mine\nmodel = \"o3\"\n\n[mcp_servers.loupe]\ndefault_tools_approval_mode = \"approve\"\ncommand = \"loupe\"\nargs = [\"mcp\"]\n\n[mcp_servers.other]\ncommand = \"x\"\n"
	if got := readConfig(t, home); got != want {
		t.Errorf("config.toml:\n%s\nwant:\n%s", got, want)
	}
	if got := (Harness{}).approvalProblems(home, "dev"); len(got) != 0 {
		t.Errorf("the bridge check still refuses it: %v", got)
	}
}

func TestApproveToolsReplacesAnotherMode(t *testing.T) {
	home := writeConfig(t, "[mcp_servers.\"loupe\"]\ncommand = \"loupe\"\ndefault_tools_approval_mode = \"prompt\"\n")

	if changed, err := ApproveTools(home); err != nil || !changed {
		t.Fatalf("ApproveTools: %v, %v", changed, err)
	}
	if got := readConfig(t, home); strings.Contains(got, "prompt") || strings.Count(got, "default_tools_approval_mode") != 1 {
		t.Errorf("config.toml:\n%s", got)
	}
}

func TestApproveToolsLeavesAnApprovedServerAlone(t *testing.T) {
	body := "[mcp_servers.loupe]\ndefault_tools_approval_mode = \"approve\"\n"
	home := writeConfig(t, body)

	if changed, err := ApproveTools(home); err != nil || changed {
		t.Fatalf("ApproveTools: %v, %v", changed, err)
	}
	if got := readConfig(t, home); got != body {
		t.Errorf("config.toml changed:\n%s", got)
	}
}

// The mode would land in another table, so the edit refuses and writes nothing.
func TestApproveToolsRefusesAFileWithNoLoupeTable(t *testing.T) {
	for name, body := range map[string]string{
		"no table":     "[mcp_servers.other]\ncommand = \"x\"\n",
		"inline table": "mcp_servers = { loupe = { command = \"loupe\" } }\n",
	} {
		t.Run(name, func(t *testing.T) {
			home := writeConfig(t, body)
			if _, err := ApproveTools(home); err == nil {
				t.Fatal("ApproveTools must refuse")
			}
			if got := readConfig(t, home); got != body {
				t.Errorf("config.toml changed:\n%s", got)
			}
		})
	}
}
