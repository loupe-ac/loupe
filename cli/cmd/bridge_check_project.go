package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"slices"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/harness/codex"
	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/spf13/cobra"
)

// projectCheck is the JSON that `loupe bridge check --project <slug> --json`
// prints.
type projectCheck struct {
	Project   string         `json:"project"`
	Dir       string         `json:"dir"`
	DirExists bool           `json:"dirExists"`
	Rules     string         `json:"rules"`
	Kinds     []kindCheck    `json:"kinds"`
	Accounts  []accountCheck `json:"accounts"`
}

type kindCheck struct {
	Kind     string         `json:"kind"`
	Source   string         `json:"source"`
	Action   string         `json:"action"`
	Account  string         `json:"account,omitempty"`
	Harness  string         `json:"harness,omitempty"`
	Commands []commandCheck `json:"commands"`
}

type commandCheck struct {
	Field    string   `json:"field"`
	Argv     []string `json:"argv"`
	Checked  bool     `json:"checked"`
	Resolved string   `json:"resolved,omitempty"`
	Exists   bool     `json:"exists"`
}

type accountCheck struct {
	Name            string   `json:"name"`
	Harness         string   `json:"harness"`
	ConfigDir       string   `json:"configDir"`
	ConfigDirExists bool     `json:"configDirExists"`
	MCPServers      []string `json:"mcpServers"`
	MCPError        string   `json:"mcpError,omitempty"`
}

func writeProjectCheck(cmd *cobra.Command, set *rules.Set, path, slug string) error {
	if !slices.Contains(set.Projects(), slug) {
		return fmt.Errorf("rule file %s has no project %q; it maps %s", path, slug, strings.Join(set.Projects(), ", "))
	}
	report := buildProjectCheck(set, path, slug)
	data, err := json.MarshalIndent(report, "", "  ")
	if err != nil {
		return err
	}
	fmt.Fprintln(cmd.OutOrStdout(), string(data))

	return nil
}

func buildProjectCheck(set *rules.Set, path, slug string) projectCheck {
	dir := set.Dir(slug)
	report := projectCheck{Project: slug, Dir: dir, Rules: path, Kinds: []kindCheck{}, Accounts: []accountCheck{}}
	report.DirExists = isDir(dir)

	kinds := slices.Concat(set.SharedWorkKinds(), set.ProjectWorkKinds(slug))
	slices.Sort(kinds)
	seen := map[string]bool{}
	for _, kind := range slices.Compact(kinds) {
		entry, ok := set.WorkEntryFor(slug, kind)
		if !ok {
			continue
		}
		k := kindCheck{Kind: kind, Source: "shared", Action: "worker", Commands: []commandCheck{}}
		if _, own := set.ProjectWorkEntry(slug, kind); own {
			k.Source = "project"
		}
		if entry.Action != "" {
			k.Action = entry.Action
		}
		run := entry.RunSettings()
		k.Account, k.Harness = run.Account, run.Harness
		for _, c := range entry.Commands() {
			k.Commands = append(k.Commands, checkCommand(dir, c))
		}
		report.Kinds = append(report.Kinds, k)

		if run.Account != "" && !seen[run.Account] {
			seen[run.Account] = true
			report.Accounts = append(report.Accounts, accountFolders(run, dir))
		}
	}

	return report
}

// checkCommand finds the program of a command the way the bridge starts it.
func checkCommand(dir string, c rules.EntryCommand) commandCheck {
	out := commandCheck{Field: c.Field, Argv: c.Argv}
	name := c.Argv[0]
	if strings.ContainsAny(name, "{~") {
		return out
	}
	out.Checked = true
	switch {
	case filepath.IsAbs(name):
		out.Resolved = name
	case strings.Contains(name, "/"):
		out.Resolved = filepath.Join(dir, name)
	default:
		if found, err := exec.LookPath(name); err == nil {
			out.Resolved, out.Exists = found, true
		}

		return out
	}
	_, err := os.Stat(out.Resolved)
	out.Exists = err == nil

	return out
}

// checkAccount reads the config folder of an account and the MCP servers that
// it declares. A file that cannot be read goes into MCPError.
func accountFolders(run rules.RunSettings, projectDir string) accountCheck {
	out := accountCheck{Name: run.Account, Harness: run.Harness, ConfigDir: run.ConfigDir, MCPServers: []string{}}
	var errs []string
	var names []string
	switch run.Harness {
	case rules.HarnessCodex:
		home := run.ConfigDir
		if home == "" {
			h, err := codex.Home()
			if err != nil {
				errs = append(errs, err.Error())
			}
			home = h
		}
		out.ConfigDirExists = isDir(home)
		files := []string{filepath.Join(home, "config.toml")}
		if run.Profile != "" {
			files = append(files, filepath.Join(home, run.Profile+".config.toml"))
		}
		for _, f := range files {
			got, err := tomlServers(f)
			names = append(names, got...)
			errs = appendReadError(errs, err)
		}
	default:
		cfgDir, stateFile := claudeStateFile(run.ConfigDir)
		out.ConfigDirExists = isDir(cfgDir)
		got, err := claudeJSONServers(stateFile, projectDir)
		names = append(names, got...)
		errs = appendReadError(errs, err)
		got, err = mcpFileServers(filepath.Join(projectDir, ".mcp.json"))
		names = append(names, got...)
		errs = appendReadError(errs, err)
	}
	slices.Sort(names)
	out.MCPServers = append(out.MCPServers, slices.Compact(names)...)
	out.MCPError = strings.Join(errs, "; ")

	return out
}

func appendReadError(errs []string, err error) []string {
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return append(errs, err.Error())
	}

	return errs
}

// claudeStateFile gives the config folder of Claude Code and the .claude.json
// file that holds its user-scope MCP servers. With CLAUDE_CONFIG_DIR the file
// sits in the folder, and else it sits in the home folder.
func claudeStateFile(configDir string) (dir, file string) {
	if configDir != "" {
		return configDir, filepath.Join(configDir, ".claude.json")
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return "", ""
	}

	return filepath.Join(home, ".claude"), filepath.Join(home, ".claude.json")
}

func claudeJSONServers(file, projectDir string) ([]string, error) {
	if file == "" {
		return nil, nil
	}
	data, err := os.ReadFile(file)
	if err != nil {
		return nil, err
	}
	var doc struct {
		MCPServers map[string]json.RawMessage `json:"mcpServers"`
		Projects   map[string]struct {
			MCPServers map[string]json.RawMessage `json:"mcpServers"`
		} `json:"projects"`
	}
	if err := json.Unmarshal(data, &doc); err != nil {
		return nil, fmt.Errorf("%s: %w", file, err)
	}
	var names []string
	for name := range doc.MCPServers {
		names = append(names, name)
	}
	for name := range doc.Projects[projectDir].MCPServers {
		names = append(names, name)
	}

	return names, nil
}

func mcpFileServers(file string) ([]string, error) {
	data, err := os.ReadFile(file)
	if err != nil {
		return nil, err
	}
	var doc struct {
		MCPServers map[string]json.RawMessage `json:"mcpServers"`
	}
	if err := json.Unmarshal(data, &doc); err != nil {
		return nil, fmt.Errorf("%s: %w", file, err)
	}
	var names []string
	for name := range doc.MCPServers {
		names = append(names, name)
	}

	return names, nil
}

var tomlServerHeader = regexp.MustCompile(`^\s*\[mcp_servers\.("([^"]+)"|[A-Za-z0-9_-]+)\]\s*(#.*)?$`)

// tomlServers reads the names of the [mcp_servers.<name>] tables of a Codex
// config file.
func tomlServers(file string) ([]string, error) {
	data, err := os.ReadFile(file)
	if err != nil {
		return nil, err
	}
	var names []string
	for _, line := range strings.Split(string(data), "\n") {
		m := tomlServerHeader.FindStringSubmatch(line)
		if m == nil {
			continue
		}
		name := m[2]
		if name == "" {
			name = m[1]
		}
		names = append(names, name)
	}

	return names, nil
}
