package codex

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

var (
	serverHeader = regexp.MustCompile(`^\s*\[\s*mcp_servers\s*\.\s*"?loupe"?\s*\]\s*(#.*)?$`)
	approvalLine = regexp.MustCompile(`^\s*"?default_tools_approval_mode"?\s*=`)
)

// ApproveTools sets default_tools_approval_mode = "approve" for the loupe MCP
// server in config.toml of the Codex home, so a worker calls the Loupe tools
// without a prompt. It edits the text, so the comments and the order of the
// file stay, and it writes nothing unless the result parses with the mode set.
// It reports whether it changed the file.
func ApproveTools(home string) (bool, error) {
	path := filepath.Join(home, "config.toml")
	files := loadFiles(path)
	if files[0].err != nil {
		return false, fmt.Errorf("%s does not parse: %w", path, files[0].err)
	}
	if files[0].cfg.McpServers["loupe"].DefaultToolsApprovalMode == "approve" {
		return false, nil
	}
	info, err := os.Stat(path)
	if err != nil {
		return false, err
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return false, err
	}

	lines := strings.Split(string(raw), "\n")
	header := -1
	for i, line := range lines {
		if serverHeader.MatchString(line) {
			header = i

			break
		}
	}
	if header < 0 {
		return false, errors.New("no [mcp_servers.loupe] table in " + path)
	}
	set := `default_tools_approval_mode = "approve"`
	replaced := false
	for i := header + 1; i < len(lines) && !strings.HasPrefix(strings.TrimSpace(lines[i]), "["); i++ {
		if approvalLine.MatchString(lines[i]) {
			lines[i], replaced = set, true

			break
		}
	}
	if !replaced {
		lines = append(lines[:header+1], append([]string{set}, lines[header+1:]...)...)
	}

	next := filepath.Join(filepath.Dir(path), ".config.toml.loupe")
	if err := os.WriteFile(next, []byte(strings.Join(lines, "\n")), info.Mode().Perm()); err != nil {
		return false, err
	}
	if got := loadFiles(next)[0]; got.err != nil || got.cfg.McpServers["loupe"].DefaultToolsApprovalMode != "approve" {
		_ = os.Remove(next)

		return false, errors.New("could not set the approval mode in " + path)
	}

	return true, os.Rename(next, path)
}

// Home is the Codex home folder: $CODEX_HOME, or ~/.codex.
func Home() (string, error) {
	if home := strings.TrimSpace(os.Getenv("CODEX_HOME")); home != "" {
		return home, nil
	}
	userHome, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}

	return filepath.Join(userHome, ".codex"), nil
}
