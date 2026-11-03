package codex

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/envfile"
	"github.com/loupe-ac/loupe/cli/internal/skills"
)

// pluginTimeout bounds one codex plugin command. An install copies a package
// into the cache of the home, so it takes longer than a check.
var pluginTimeout = 2 * time.Minute

// Plugin is a Loupe plugin that a Codex home has installed.
type Plugin struct {
	Name    string
	Version string
	Enabled bool
}

// HomeEnv is env with CODEX_HOME set to home.
func HomeEnv(env []string, home string) []string {
	return envfile.Overlay(env, []string{"CODEX_HOME=" + home})
}

// ListPlugins reads the plugins of the Loupe marketplace that the home has
// installed, by name. env holds CODEX_HOME.
func ListPlugins(ctx context.Context, binary string, env []string) (map[string]Plugin, error) {
	out, err := runPlugin(ctx, checkTimeout, binary, env, false, "plugin", "list", "--json")
	if err != nil {
		return nil, err
	}
	var list struct {
		Installed []struct {
			Name        string `json:"name"`
			Marketplace string `json:"marketplaceName"`
			Version     string `json:"version"`
			Installed   bool   `json:"installed"`
			Enabled     bool   `json:"enabled"`
		} `json:"installed"`
	}
	if err := json.Unmarshal(out, &list); err != nil {
		return nil, fmt.Errorf("codex plugin list --json did not print JSON: %w", err)
	}
	plugins := map[string]Plugin{}
	for _, p := range list.Installed {
		if p.Marketplace == skills.MarketplaceName && p.Installed {
			plugins[p.Name] = Plugin{Name: p.Name, Version: p.Version, Enabled: p.Enabled}
		}
	}

	return plugins, nil
}

// PluginsToInstall lists the picks whose installed version differs from the
// version in the store, and the picks that are not installed. An installed
// version that matches stays: an install deletes the cache of the old version,
// and a running worker reads its files.
func PluginsToInstall(have map[string]Plugin, picks []skills.Pick) []skills.Pick {
	var todo []skills.Pick
	for _, p := range picks {
		if cur, ok := have[p.Package]; !ok || cur.Version != p.Version {
			todo = append(todo, p)
		}
	}

	return todo
}

// SyncPlugins installs each pick that the home lacks or holds at another
// version, and returns the packages it installed. It writes nothing to the home
// when every version matches. env holds CODEX_HOME.
func SyncPlugins(ctx context.Context, binary string, env []string, marketplace string, picks []skills.Pick) ([]string, error) {
	have, err := ListPlugins(ctx, binary, env)
	if err != nil {
		return nil, err
	}

	return InstallPlugins(ctx, binary, env, marketplace, PluginsToInstall(have, picks))
}

// InstallPlugins installs each pick in the home and returns the packages it
// installed. It adds the marketplace first, and replaces a marketplace of the
// same name that points at another folder.
func InstallPlugins(ctx context.Context, binary string, env []string, marketplace string, todo []skills.Pick) ([]string, error) {
	if len(todo) == 0 {
		return nil, nil
	}
	if err := ensureMarketplace(ctx, binary, env, marketplace); err != nil {
		return nil, err
	}
	var installed []string
	for _, p := range todo {
		if _, err := runPlugin(ctx, pluginTimeout, binary, env, true, "plugin", "add", p.Package+"@"+skills.MarketplaceName); err != nil {
			return installed, fmt.Errorf("install plugin %s: %w", p.Package, err)
		}
		installed = append(installed, p.Package)
	}

	return installed, nil
}

// ensureMarketplace makes the home know the marketplace at root.
func ensureMarketplace(ctx context.Context, binary string, env []string, root string) error {
	out, err := runPlugin(ctx, checkTimeout, binary, env, false, "plugin", "marketplace", "list", "--json")
	if err != nil {
		return err
	}
	var list struct {
		Marketplaces []struct {
			Name string `json:"name"`
			Root string `json:"root"`
		} `json:"marketplaces"`
	}
	if err := json.Unmarshal(out, &list); err != nil {
		return fmt.Errorf("codex plugin marketplace list --json did not print JSON: %w", err)
	}
	for _, m := range list.Marketplaces {
		if m.Name != skills.MarketplaceName {
			continue
		}
		if sameFolder(m.Root, root) {
			return nil
		}
		if _, err := runPlugin(ctx, pluginTimeout, binary, env, true, "plugin", "marketplace", "remove", skills.MarketplaceName); err != nil {
			return fmt.Errorf("remove the marketplace of another folder: %w", err)
		}
	}
	if _, err := runPlugin(ctx, pluginTimeout, binary, env, true, "plugin", "marketplace", "add", root); err != nil && !strings.Contains(err.Error(), "already added") {
		return fmt.Errorf("add the marketplace: %w", err)
	}

	return nil
}

// sameFolder compares two folders after it resolves their symlinks, because
// Codex records the resolved path.
func sameFolder(a, b string) bool {
	return filepath.Clean(realPath(a)) == filepath.Clean(realPath(b))
}

// runPlugin runs one codex command with a timeout. A failure carries the text
// codex printed. A run that wants all output reads stderr too.
func runPlugin(ctx context.Context, timeout time.Duration, binary string, env []string, combined bool, args ...string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, binary, args...)
	cmd.Env, cmd.WaitDelay = env, time.Second

	var out []byte
	var err error
	if combined {
		out, err = cmd.CombinedOutput()
	} else {
		out, err = cmd.Output()
	}
	if err == nil {
		return out, nil
	}
	detail := strings.TrimSpace(string(out))
	var exit *exec.ExitError
	if errors.As(err, &exit) && detail == "" {
		detail = strings.TrimSpace(string(exit.Stderr))
	}
	if ctx.Err() != nil {
		err = ctx.Err()
	}
	if detail == "" {
		return out, fmt.Errorf("codex %s: %w", strings.Join(args, " "), err)
	}

	return out, fmt.Errorf("codex %s: %w: %s", strings.Join(args, " "), err, lastLine(detail))
}

// lastLine is the last line of text, where codex prints its error.
func lastLine(text string) string {
	if i := strings.LastIndex(text, "\n"); i >= 0 {
		return strings.TrimSpace(text[i+1:])
	}

	return text
}

// LegacySkillFolders lists the folders in the skills folder of the user home,
// where Codex read the Loupe skills before they became plugins, that carry one of
// names. names holds the skills of the plugins. Any other folder stays out, also
// one whose name starts with loupe-, because no plugin replaces it.
func LegacySkillFolders(names []string) []string {
	home, err := os.UserHomeDir()
	if err != nil {
		return nil
	}
	dir := filepath.Join(home, ".agents", "skills")
	var found []string
	for _, name := range names {
		path := filepath.Join(dir, name)
		if info, err := os.Lstat(path); err == nil && (info.IsDir() || info.Mode()&os.ModeSymlink != 0) {
			found = append(found, path)
		}
	}

	return found
}

// BackupLegacySkills moves each folder of LegacySkillFolders out of the skills
// folder Codex reads, into backupDir/<date>/. Codex reads that folder for every
// home, so a folder that stays shows each skill twice. It returns the folder it
// made, and "" when there was nothing to move.
func BackupLegacySkills(backupRoot string, names []string, now time.Time) (string, error) {
	folders := LegacySkillFolders(names)
	if len(folders) == 0 {
		return "", nil
	}
	dest := filepath.Join(backupRoot, now.Format("2006-01-02"))
	if err := os.MkdirAll(dest, 0o755); err != nil {
		return "", err
	}
	for _, from := range folders {
		to := filepath.Join(dest, filepath.Base(from))
		if _, err := os.Lstat(to); err == nil {
			to += now.Format("-150405")
		}
		if err := os.Rename(from, to); err != nil {
			return dest, fmt.Errorf("move %s to %s: %w", from, dest, err)
		}
	}

	return dest, nil
}
