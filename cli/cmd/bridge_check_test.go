package cmd

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/rules"
)

const checkFile = "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\n"

func runBridgeCheck(t *testing.T, args ...string) (string, error) {
	t.Helper()
	var out, errOut bytes.Buffer
	cmd := newRootCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&errOut)
	cmd.SetArgs(append([]string{"bridge", "check"}, args...))
	err := cmd.Execute()

	return out.String(), err
}

func writeCheckRules(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func TestBridgeCheckListsWhatAProjectCannotRun(t *testing.T) {
	loupe, plans := t.TempDir(), t.TempDir()
	path := writeCheckRules(t, checkFile+"projects:\n  loupe:\n    dir: "+loupe+"\n    work:\n      review:\n        prompt: x\n        before:\n          run: [bin/before.sh]\n  plans:\n    dir: "+plans+"\nwork:\n  fix:\n    prompt: x\n")

	out, err := runBridgeCheck(t, "--rules", path)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"2 warning(s)", "project loupe: work review before runs bin/before.sh, which does not exist in " + loupe, "project plans: lists no work entry review"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestBridgeCheckPassesAFileWithNoWarning(t *testing.T) {
	path := writeCheckRules(t, checkFile+"projects:\n  loupe:\n    dir: "+t.TempDir()+"\nwork:\n  fix:\n    prompt: x\n")

	out, err := runBridgeCheck(t, "--rules", path)
	if err != nil || !strings.Contains(out, "loads, and every project can run every kind it lists") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
}

func TestBridgeCheckFailsOnAFileThatDoesNotLoad(t *testing.T) {
	path := writeCheckRules(t, checkFile+"projects:\n  loupe:\n    dir: "+t.TempDir()+"\n    work:\n      x:\n        action: command\n        run: [make]\n")

	if _, err := runBridgeCheck(t, "--rules", path); err == nil || !strings.Contains(err.Error(), "not allowed under a project") {
		t.Fatalf("err = %v", err)
	}
}

func TestBridgeStatusShowsTheWarnings(t *testing.T) {
	shortConfigHome(t)
	res := validStatus("laptop")
	res.Warnings = []string{"project plans: lists no work entry review, which another project lists"}
	fakeBridge(t, "rules.yaml", statusOps(res))

	out, err := runBridgeStatus(t)
	if err != nil || !strings.Contains(out, "Warning:     project plans: lists no work entry review") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
}

func TestDiffRulesNamesAProjectEntryWithItsSlug(t *testing.T) {
	parse := func(prompt string, shared bool) *rules.Set {
		t.Helper()
		body := checkFile + "projects:\n  loupe:\n    dir: " + t.TempDir() + "\n    work:\n      review:\n        prompt: " + prompt + "\n"
		if shared {
			body += "work:\n  fix:\n    prompt: x\n"
		}
		set, err := rules.Parse([]byte(body), rules.Defaults{})
		if err != nil {
			t.Fatal(err)
		}

		return set
	}

	res := diffRules(parse("one", true), parse("two", false))
	if !slices.Equal(res.Changed, []string{"loupe/review"}) || !slices.Equal(res.Removed, []string{"fix"}) || len(res.Added) != 0 {
		t.Fatalf("result = %+v", res)
	}
	res = diffRules(parse("one", false), parse("one", true))
	if !slices.Equal(res.Added, []string{"fix"}) || len(res.Changed) != 0 {
		t.Fatalf("result = %+v", res)
	}
}

func TestBridgeCheckProjectJSONReportsCommandsAndAccounts(t *testing.T) {
	loupe := t.TempDir()
	if err := os.MkdirAll(filepath.Join(loupe, "bin"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(loupe, "bin", "before.sh"), []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(loupe, ".mcp.json"), []byte(`{"mcpServers":{"loupe":{"command":"loupe"}}}`), 0o600); err != nil {
		t.Fatal(err)
	}
	claudeDir := t.TempDir()
	if err := os.WriteFile(filepath.Join(claudeDir, ".claude.json"), []byte(`{"mcpServers":{"github":{}},"projects":{"`+loupe+`":{"mcpServers":{"local":{}}}}}`), 0o600); err != nil {
		t.Fatal(err)
	}
	codexHome := t.TempDir()
	if err := os.WriteFile(filepath.Join(codexHome, "config.toml"), []byte("[mcp_servers.loupe]\ncommand = \"loupe\"\n[mcp_servers.loupe.env]\nA = \"b\"\n[mcp_servers.\"codex-cli\"]\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	body := "accounts:\n  claude:\n    harness: claude-code\n    configDir: " + claudeDir + "\n  cx:\n    harness: codex\n    codexHome: " + codexHome + "\ndefaults:\n  account: claude\n" +
		"projects:\n  loupe:\n    dir: " + loupe + "\n    work:\n      review:\n        prompt: x\n        account: cx\n        before:\n          run: [bin/before.sh]\n        reset:\n          run: [bin/missing.sh]\n" +
		"work:\n  implement:\n    prompt: x\n  e2e:\n    action: command\n    run: [sh, -c, true]\n    description: d\n"
	path := writeCheckRules(t, body)

	out, err := runBridgeCheck(t, "--rules", path, "--project", "loupe", "--json")
	if err != nil {
		t.Fatal(err)
	}
	var got projectCheck
	if err := json.Unmarshal([]byte(out), &got); err != nil {
		t.Fatalf("not JSON: %v\n%s", err, out)
	}
	if got.Project != "loupe" || got.Dir != loupe || !got.DirExists || got.Rules != path {
		t.Errorf("header = %+v", got)
	}
	kinds := map[string]kindCheck{}
	for _, k := range got.Kinds {
		kinds[k.Kind] = k
	}
	review := kinds["review"]
	if review.Source != "project" || review.Account != "cx" || review.Harness != "codex" || len(review.Commands) != 2 {
		t.Fatalf("review = %+v", review)
	}
	if c := review.Commands[0]; c.Field != "before" || !c.Checked || !c.Exists || c.Resolved != filepath.Join(loupe, "bin", "before.sh") {
		t.Errorf("before = %+v", c)
	}
	if c := review.Commands[1]; c.Field != "reset" || !c.Checked || c.Exists {
		t.Errorf("reset = %+v", c)
	}
	if k := kinds["implement"]; k.Source != "shared" || k.Action != "worker" || k.Account != "claude" {
		t.Errorf("implement = %+v", k)
	}
	if k := kinds["e2e"]; k.Action != "command" || len(k.Commands) != 1 || !k.Commands[0].Exists || k.Commands[0].Field != "run" {
		t.Errorf("e2e = %+v", k)
	}
	accounts := map[string]accountCheck{}
	for _, a := range got.Accounts {
		accounts[a.Name] = a
	}
	if a := accounts["claude"]; !a.ConfigDirExists || !slices.Equal(a.MCPServers, []string{"github", "local", "loupe"}) || a.MCPError != "" {
		t.Errorf("claude = %+v", a)
	}
	if a := accounts["cx"]; !a.ConfigDirExists || !slices.Equal(a.MCPServers, []string{"codex-cli", "loupe"}) {
		t.Errorf("cx = %+v", a)
	}
}

func TestBridgeCheckProjectRefusesAnUnknownProject(t *testing.T) {
	path := writeCheckRules(t, checkFile+"projects:\n  loupe:\n    dir: "+t.TempDir()+"\nwork:\n  fix:\n    prompt: x\n")

	if _, err := runBridgeCheck(t, "--rules", path, "--project", "other", "--json"); err == nil || !strings.Contains(err.Error(), `no project "other"`) {
		t.Fatalf("err = %v", err)
	}
}

func TestBridgeCheckProjectNeedsJSON(t *testing.T) {
	path := writeCheckRules(t, checkFile)

	if _, err := runBridgeCheck(t, "--rules", path, "--project", "loupe"); err == nil {
		t.Fatal("--project alone was accepted")
	}
}

func TestAccountFoldersLeavesOutADisabledCodexServer(t *testing.T) {
	home := t.TempDir()
	base := "[mcp_servers.on]\ncommand = \"a\"\n[mcp_servers.off]\nenabled = false\n[mcp_servers.later]\nenabled = false\n"
	if err := os.WriteFile(filepath.Join(home, "config.toml"), []byte(base), 0o600); err != nil {
		t.Fatal(err)
	}
	profile := "[mcp_servers.later]\nenabled = true\n[mcp_servers.on]\nenabled = false\n"
	if err := os.WriteFile(filepath.Join(home, "work.config.toml"), []byte(profile), 0o600); err != nil {
		t.Fatal(err)
	}

	got := accountFolders(rules.RunSettings{Harness: rules.HarnessCodex, ConfigDir: home}, t.TempDir())
	if !slices.Equal(got.MCPServers, []string{"on"}) || got.MCPError != "" {
		t.Errorf("base only = %+v", got)
	}
	got = accountFolders(rules.RunSettings{Harness: rules.HarnessCodex, ConfigDir: home, Profile: "work"}, t.TempDir())
	if !slices.Equal(got.MCPServers, []string{"later"}) || got.MCPError != "" {
		t.Errorf("with profile = %+v", got)
	}
}

func TestAccountFoldersLeavesOutADisabledClaudeServer(t *testing.T) {
	project := t.TempDir()
	if err := os.WriteFile(filepath.Join(project, ".mcp.json"), []byte(`{"mcpServers":{"repo":{},"repo-off":{}}}`), 0o600); err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	state := `{"mcpServers":{"user":{},"user-off":{}},"projects":{"` + project + `":{"mcpServers":{"local":{}},"disabledMcpServers":["user-off"],"disabledMcpjsonServers":["repo-off"]}}}`
	if err := os.WriteFile(filepath.Join(dir, ".claude.json"), []byte(state), 0o600); err != nil {
		t.Fatal(err)
	}

	got := accountFolders(rules.RunSettings{Harness: rules.HarnessClaudeCode, ConfigDir: dir}, project)
	if !slices.Equal(got.MCPServers, []string{"local", "repo", "user"}) || got.MCPError != "" {
		t.Errorf("claude = %+v", got)
	}
}
