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
// server, so a worker calls the Loupe tools without a prompt. It edits the file
// that decides the mode, as the bridge check reads it: the profile file when it
// sets a mode, else config.toml of the Codex home. When neither sets one, it
// edits config.toml, or the profile file when only the profile declares the
// server. It returns that file, and whether it changed it.
func ApproveTools(home, profile string) (string, bool, error) {
	base := filepath.Join(home, "config.toml")
	paths := []string{base}
	if profile != "" {
		paths = []string{profileFile(home, profile), base}
	}
	files := loadFiles(paths...)
	target := base
	for _, f := range files {
		if f.err != nil {
			return f.path, false, fmt.Errorf("%s does not parse: %w", f.path, f.err)
		}
		switch f.cfg.McpServers["loupe"].DefaultToolsApprovalMode {
		case "":
		case "approve":
			return f.path, false, nil
		default:
			changed, err := approveIn(f.path)

			return f.path, changed, err
		}
	}
	if _, inBase := files[len(files)-1].cfg.McpServers["loupe"]; !inBase && len(files) > 1 {
		if _, inProfile := files[0].cfg.McpServers["loupe"]; inProfile {
			target = files[0].path
		}
	}
	changed, err := approveIn(target)

	return target, changed, err
}

// approveIn sets the mode in one file. It edits the text, so the comments and
// the order of the file stay, and it writes nothing unless the result parses
// with the mode set. It reports whether it changed the file.
func approveIn(path string) (bool, error) {
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

	next := filepath.Join(filepath.Dir(path), "."+filepath.Base(path)+".loupe")
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
