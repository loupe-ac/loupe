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

// A profile can declare the server that config.toml lacks. The mode then goes
// in the profile, because config.toml has no table to put it in.
func TestApproveToolsApprovesAServerOnlyTheProfileDeclares(t *testing.T) {
	home := writeConfig(t, "model = \"o3\"\n")
	profile := filepath.Join(home, "work.config.toml")
	if err := os.WriteFile(profile, []byte("[mcp_servers.loupe]\ncommand = \"loupe\"\nargs = [\"mcp\"]\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	path, changed, err := ApproveTools(home, "work")
	if err != nil || !changed || path != profile {
		t.Fatalf("ApproveTools: %s, %v, %v", path, changed, err)
	}
	if got := (Harness{profile: "work"}).approvalProblems(home, "dev"); len(got) != 0 {
		t.Errorf("the bridge check still refuses it: %v", got)
	}
	if got := readConfig(t, home); got != "model = \"o3\"\n" {
		t.Errorf("config.toml changed:\n%s", got)
	}
}

func TestProfileDeclarationFindsAServerTheProfileStarts(t *testing.T) {
	home := writeConfig(t, "[mcp_servers.loupe]\ncommand = \"loupe\"\nargs = [\"mcp\"]\n")
	profile := filepath.Join(home, "work.config.toml")
	for body, want := range map[string]bool{
		"[mcp_servers.loupe]\ncommand = \"npx\"\n":                        true,
		"[mcp_servers.loupe]\nurl = \"https://loupe.test/mcp\"\n":         true,
		"[mcp_servers.loupe]\ndefault_tools_approval_mode = \"prompt\"\n": false,
		"model = \"o3\"\n": false,
	} {
		if err := os.WriteFile(profile, []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
		if path, got := ProfileDeclaration(home, "work"); got != want || path != profile {
			t.Errorf("ProfileDeclaration with %q: %s, %v", body, path, got)
		}
	}
	if _, got := ProfileDeclaration(home, ""); got {
		t.Error("ProfileDeclaration found a declaration with no profile")
	}
}

func TestEnableServerEditsTheProfileThatDisablesIt(t *testing.T) {
	home := writeConfig(t, "[mcp_servers.loupe]\ncommand = \"loupe\"\nenabled = true\n")
	profile := filepath.Join(home, "work.config.toml")
	if err := os.WriteFile(profile, []byte("[mcp_servers.loupe]\nenabled = false # off\n[other]\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	path, changed, err := EnableServer(home, "work")
	if err != nil || !changed || path != profile {
		t.Fatalf("EnableServer: %s, %v, %v", path, changed, err)
	}
	raw, _ := os.ReadFile(profile)
	if string(raw) != "[mcp_servers.loupe]\nenabled = true\n[other]\n" {
		t.Errorf("profile:\n%s", raw)
	}
}

func TestEnableServerEditsConfigWhenTheProfileIsSilent(t *testing.T) {
	home := writeConfig(t, "# mine\n[mcp_servers.loupe]\ncommand = \"loupe\"\nenabled = false\n")

	path, changed, err := EnableServer(home, "work")
	if err != nil || !changed || path != filepath.Join(home, "config.toml") {
		t.Fatalf("EnableServer: %s, %v, %v", path, changed, err)
	}
	if got := readConfig(t, home); got != "# mine\n[mcp_servers.loupe]\ncommand = \"loupe\"\nenabled = true\n" {
		t.Errorf("config.toml:\n%s", got)
	}
}

func TestEnableServerChangesNothingWhenNoFileSetsEnabled(t *testing.T) {
	home := writeConfig(t, "[mcp_servers.loupe]\ncommand = \"loupe\"\n")

	if _, changed, err := EnableServer(home, ""); err != nil || changed {
		t.Fatalf("EnableServer: %v, %v", changed, err)
	}
}

func TestServerDisabledReadsTheFirstFileThatSetsEnabled(t *testing.T) {
	home := writeConfig(t, "[mcp_servers.loupe]\nenabled = true\n")
	if ServerDisabled(home, "work") {
		t.Error("config.toml enables the server")
	}
	if err := os.WriteFile(filepath.Join(home, "work.config.toml"), []byte("[mcp_servers.loupe]\nenabled = false\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if !ServerDisabled(home, "work") {
		t.Error("the profile disables the server")
	}
	if ServerDisabled(home, "") {
		t.Error("with no profile, config.toml decides")
	}
}

func TestReplaceProfileServerMakesTheProfileStartLoupeMcp(t *testing.T) {
	for name, body := range map[string]string{
		"command": "# mine\n[mcp_servers.loupe]\ncommand = \"npx\"\nargs = [\n  \"-y\",\n  \"tool\",\n]\nenabled = true\n\n[mcp_servers.other]\ncommand = \"x\"\n",
		"url":     "# mine\n[mcp_servers.loupe]\nurl = \"https://loupe.test/mcp\"\nbearer_token_env_var = \"TOKEN\"\nenabled = true\n\n[mcp_servers.other]\ncommand = \"x\"\n",
	} {
		home := t.TempDir()
		profile := filepath.Join(home, "work.config.toml")
		if err := os.WriteFile(profile, []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
		if err := ReplaceProfileServer(profile); err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		got, _ := os.ReadFile(profile)
		want := "# mine\n[mcp_servers.loupe]\ncommand = \"loupe\"\nargs = [\"mcp\"]\nenabled = true\n\n[mcp_servers.other]\ncommand = \"x\"\n"
		if string(got) != want {
			t.Errorf("%s: the profile reads\n%s", name, got)
		}
	}
}

func TestReplaceProfileServerRefusesHttpHeaders(t *testing.T) {
	profile := filepath.Join(t.TempDir(), "work.config.toml")
	body := "[mcp_servers.loupe]\nurl = \"https://loupe.test/mcp\"\n\n[mcp_servers.loupe.http_headers]\nX = \"y\"\n"
	if err := os.WriteFile(profile, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := ReplaceProfileServer(profile); err == nil {
		t.Error("ReplaceProfileServer rewrote a server with HTTP headers")
	}
	if got, _ := os.ReadFile(profile); string(got) != body {
		t.Errorf("the profile changed:\n%s", got)
	}
}
