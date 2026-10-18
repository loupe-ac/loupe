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

	_, changed, err := ApproveTools(home, "")
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

	if _, changed, err := ApproveTools(home, ""); err != nil || !changed {
		t.Fatalf("ApproveTools: %v, %v", changed, err)
	}
	if got := readConfig(t, home); strings.Contains(got, "prompt") || strings.Count(got, "default_tools_approval_mode") != 1 {
		t.Errorf("config.toml:\n%s", got)
	}
}

func TestApproveToolsLeavesAnApprovedServerAlone(t *testing.T) {
	body := "[mcp_servers.loupe]\ndefault_tools_approval_mode = \"approve\"\n"
	home := writeConfig(t, body)

	if _, changed, err := ApproveTools(home, ""); err != nil || changed {
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
			if _, _, err := ApproveTools(home, ""); err == nil {
				t.Fatal("ApproveTools must refuse")
			}
			if got := readConfig(t, home); got != body {
				t.Errorf("config.toml changed:\n%s", got)
			}
		})
	}
}

// The profile file wins over config.toml in the bridge check, so the edit goes
// where the check reads the mode.
func TestApproveToolsEditsAProfileThatOverridesTheMode(t *testing.T) {
	home := writeConfig(t, "[mcp_servers.loupe]\ncommand = \"loupe\"\ndefault_tools_approval_mode = \"approve\"\n")
	profile := filepath.Join(home, "work.config.toml")
	if err := os.WriteFile(profile, []byte("# mine\n[mcp_servers.loupe]\ndefault_tools_approval_mode = \"prompt\"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	h := Harness{profile: "work"}
	if got := h.approvalProblems(home, "dev"); len(got) == 0 {
		t.Fatal("the bridge check must refuse the profile before the edit")
	}

	path, changed, err := ApproveTools(home, "work")
	if err != nil || !changed || path != profile {
		t.Fatalf("ApproveTools: %q, %v, %v", path, changed, err)
	}
	if got := h.approvalProblems(home, "dev"); len(got) != 0 {
		t.Errorf("the bridge check still refuses it: %v", got)
	}
	raw, err := os.ReadFile(profile)
	if err != nil {
		t.Fatal(err)
	}
	if got := string(raw); got != "# mine\n[mcp_servers.loupe]\ndefault_tools_approval_mode = \"approve\"\n" {
		t.Errorf("profile file:\n%s", got)
	}
}

// A profile that sets no mode leaves the decision to config.toml.
func TestApproveToolsEditsConfigWhenTheProfileIsSilent(t *testing.T) {
	home := writeConfig(t, "[mcp_servers.loupe]\ncommand = \"loupe\"\n")
	if err := os.WriteFile(filepath.Join(home, "work.config.toml"), []byte("model = \"o3\"\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	path, changed, err := ApproveTools(home, "work")
	if err != nil || !changed || path != filepath.Join(home, "config.toml") {
		t.Fatalf("ApproveTools: %q, %v, %v", path, changed, err)
	}
	if got := (Harness{profile: "work"}).approvalProblems(home, "dev"); len(got) != 0 {
		t.Errorf("the bridge check still refuses it: %v", got)
	}
}
