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
	enabledLine  = regexp.MustCompile(`^\s*"?enabled"?\s*=`)
	// transportLine matches a key that says how Codex starts the server.
	transportLine = regexp.MustCompile(`^\s*"?(command|args|url|bearer_token_env_var|http_headers|env_http_headers)"?\s*=`)
	headerTable   = regexp.MustCompile(`^\s*\[\s*mcp_servers\s*\.\s*"?loupe"?\s*\.\s*"?(http_headers|env_http_headers)"?\s*\]`)
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

// approveIn sets the mode in one file. It reports whether it changed the file.
func approveIn(path string) (bool, error) {
	return setInServer(path, approvalLine, `default_tools_approval_mode = "approve"`, func(s serverConf) bool {
		return s.DefaultToolsApprovalMode == "approve"
	})
}

// EnableServer sets enabled = true for the loupe MCP server, in the first file
// that sets enabled: the profile file, then config.toml of the Codex home. It
// returns that file, and whether it changed it.
func EnableServer(home, profile string) (string, bool, error) {
	base := filepath.Join(home, "config.toml")
	paths := []string{base}
	if profile != "" {
		paths = []string{profileFile(home, profile), base}
	}
	for _, f := range loadFiles(paths...) {
		if f.err != nil {
			return f.path, false, fmt.Errorf("%s does not parse: %w", f.path, f.err)
		}
		if f.cfg.McpServers["loupe"].Enabled != nil {
			changed, err := setInServer(f.path, enabledLine, "enabled = true", func(s serverConf) bool {
				return s.Enabled == nil || *s.Enabled
			})

			return f.path, changed, err
		}
	}

	return base, false, nil
}

// setInServer sets one key of the [mcp_servers.loupe] table in one file. It
// edits the text, so the comments and the order of the file stay, and it
// writes nothing unless the result parses and done accepts it. It reports
// whether it changed the file.
func setInServer(path string, key *regexp.Regexp, set string, done func(serverConf) bool) (bool, error) {
	files := loadFiles(path)
	if files[0].err != nil {
		return false, fmt.Errorf("%s does not parse: %w", path, files[0].err)
	}
	if _, ok := files[0].cfg.McpServers["loupe"]; ok && done(files[0].cfg.McpServers["loupe"]) {
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
	replaced := false
	for i := header + 1; i < len(lines) && !strings.HasPrefix(strings.TrimSpace(lines[i]), "["); i++ {
		if key.MatchString(lines[i]) {
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
	if got := loadFiles(next)[0]; got.err != nil || !done(got.cfg.McpServers["loupe"]) {
		_ = os.Remove(next)

		return false, errors.New("could not set " + set + " in " + path)
	}

	return true, os.Rename(next, path)
}

// ReplaceProfileServer makes the loupe MCP server of a profile file start
// `loupe mcp`. It drops the keys that say how Codex starts the server, and
// writes command and args under the table header. It keeps the other keys and
// the comments, and writes nothing unless the result parses as `loupe mcp`.
func ReplaceProfileServer(path string) error {
	info, err := os.Stat(path)
	if err != nil {
		return err
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	lines := strings.Split(string(raw), "\n")
	kept := make([]string, 0, len(lines)+2)
	inServer, inArray, found := false, false, false
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		switch {
		case inArray:
			inArray = !strings.Contains(trimmed, "]")

			continue
		case headerTable.MatchString(line):
			return errors.New(path + " sets HTTP headers for the loupe server")
		case serverHeader.MatchString(line):
			inServer, found = true, true
			kept = append(kept, line, `command = "loupe"`, `args = ["mcp"]`)

			continue
		case strings.HasPrefix(trimmed, "["):
			inServer = false
		case inServer && transportLine.MatchString(line):
			value := strings.TrimSpace(line[strings.Index(line, "=")+1:])
			inArray = strings.HasPrefix(value, "[") && !strings.Contains(value, "]")

			continue
		}
		kept = append(kept, line)
	}
	if !found {
		return errors.New("no [mcp_servers.loupe] table in " + path)
	}

	next := filepath.Join(filepath.Dir(path), "."+filepath.Base(path)+".loupe")
	if err := os.WriteFile(next, []byte(strings.Join(kept, "\n")), info.Mode().Perm()); err != nil {
		return err
	}
	got := loadFiles(next)[0]
	s := got.cfg.McpServers["loupe"]
	if got.err != nil || s.Command != "loupe" || s.URL != "" || len(s.Args) != 1 || s.Args[0] != "mcp" {
		_ = os.Remove(next)

		return errors.New("could not set the loupe server to `loupe mcp` in " + path)
	}

	return os.Rename(next, path)
}

// ProfileDeclaration returns the profile file when it declares the loupe MCP
// server with its own command or url. Codex then starts that declaration, and
// `codex mcp add` and `codex mcp remove` change only config.toml.
func ProfileDeclaration(home, profile string) (string, bool) {
	if profile == "" {
		return "", false
	}
	f := loadFiles(profileFile(home, profile))[0]
	s := f.cfg.McpServers["loupe"]

	return f.path, f.err == nil && (s.Command != "" || s.URL != "")
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
