package skills

import (
	"bytes"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
)

// MarketplaceName is the name of the local marketplace that Codex reads the
// Loupe plugins from.
const MarketplaceName = "loupe-local"

// marketplaceDir is the folder in the store that holds the marketplace. A
// leading dot keeps Held from taking it for a package.
const marketplaceDir = ".codex-marketplace"

// codexManifest is the plugin manifest that Codex reads in a package.
const codexManifest = ".codex-plugin/plugin.json"

// MarketplaceDir is the root of the local Codex marketplace.
func MarketplaceDir(configDir string) string {
	return filepath.Join(StoreDir(configDir), marketplaceDir)
}

// CodexPicks picks the current version of each package in the store that Codex
// can load, in package order. A package that has no usable version, or no Codex
// manifest, is left out, so it never blocks the others.
func CodexPicks(configDir, cliVersion string) []Pick {
	held := Held(configDir)
	slices.Sort(held)
	picks, err := Resolve(configDir, cliVersion, held)
	if err != nil {
		picks = nil
		for _, pkg := range held {
			if one, err := Resolve(configDir, cliVersion, []string{pkg}); err == nil {
				picks = append(picks, one...)
			}
		}
	}

	return slices.DeleteFunc(picks, func(p Pick) bool {
		_, err := os.Stat(filepath.Join(p.Dir, codexManifest))

		return err != nil
	})
}

// SkillNames lists the skills of a package folder.
func SkillNames(dir string) []string {
	entries, _ := os.ReadDir(filepath.Join(dir, "skills"))
	var names []string
	for _, e := range entries {
		if e.IsDir() && !strings.HasPrefix(e.Name(), ".") {
			names = append(names, e.Name())
		}
	}

	return names
}

// HiddenSkills names, as <plugin>:<skill>, each skill of a package that is not
// in keep. Codex takes this name to hide a skill. A name in keep also keeps the
// packages it needs.
func HiddenSkills(configDir, cliVersion string, keep []string) []string {
	kept := map[string]bool{}
	for key := range Current(configDir, cliVersion, [][]string{keep}) {
		pkg, _, _ := strings.Cut(key, "/")
		kept[pkg] = true
	}
	for _, pkg := range keep {
		kept[pkg] = true
	}
	var hidden []string
	for _, p := range CodexPicks(configDir, cliVersion) {
		if kept[p.Package] {
			continue
		}
		for _, skill := range SkillNames(p.Dir) {
			hidden = append(hidden, p.Package+":"+skill)
		}
	}

	return hidden
}

type marketplaceFile struct {
	Name    string              `json:"name"`
	Plugins []marketplacePlugin `json:"plugins"`
}

type marketplacePlugin struct {
	Name     string            `json:"name"`
	Source   marketplaceSource `json:"source"`
	Policy   marketplacePolicy `json:"policy"`
	Category string            `json:"category"`
}

type marketplaceSource struct {
	Source string `json:"source"`
	Path   string `json:"path"`
}

type marketplacePolicy struct {
	Installation   string `json:"installation"`
	Authentication string `json:"authentication"`
}

// BuildMarketplace makes the local marketplace match the store: a plugin link
// for the current version of each package, and the file that lists them. Each
// file changes with a rename, so a reader never sees a half-written one. The
// root keeps its path, because Codex records it. It returns the picks the
// marketplace holds, and writes nothing when there are none.
func BuildMarketplace(configDir, cliVersion string) ([]Pick, error) {
	picks := CodexPicks(configDir, cliVersion)
	if len(picks) == 0 {
		return nil, nil
	}
	root := MarketplaceDir(configDir)
	if err := os.MkdirAll(filepath.Join(root, ".agents", "plugins"), 0o755); err != nil {
		return nil, err
	}
	linkDir := filepath.Join(root, "plugins")
	if err := os.MkdirAll(linkDir, 0o755); err != nil {
		return nil, err
	}

	file := marketplaceFile{Name: MarketplaceName}
	wanted := map[string]bool{}
	for _, p := range picks {
		target, err := filepath.Abs(p.Dir)
		if err != nil {
			return nil, err
		}
		if err := replaceLink(filepath.Join(linkDir, p.Package), target); err != nil {
			return nil, err
		}
		wanted[p.Package] = true
		file.Plugins = append(file.Plugins, marketplacePlugin{
			Name:     p.Package,
			Source:   marketplaceSource{Source: "local", Path: "./plugins/" + p.Package},
			Policy:   marketplacePolicy{Installation: "AVAILABLE", Authentication: "ON_INSTALL"},
			Category: "Productivity",
		})
	}
	entries, _ := os.ReadDir(linkDir)
	for _, e := range entries {
		if !wanted[e.Name()] {
			_ = os.RemoveAll(filepath.Join(linkDir, e.Name()))
		}
	}

	data, err := json.MarshalIndent(file, "", "  ")
	if err != nil {
		return nil, err
	}

	return picks, writeAtomic(filepath.Join(root, ".agents", "plugins", "marketplace.json"), append(data, '\n'))
}

// replaceLink points the symlink at target. A link that points there already
// stays, and any other entry is replaced with a rename.
func replaceLink(link, target string) error {
	if cur, err := os.Readlink(link); err == nil && cur == target {
		return nil
	}
	tmp := link + ".tmp"
	_ = os.Remove(tmp)
	if err := os.Symlink(target, tmp); err != nil {
		return err
	}
	if info, err := os.Lstat(link); err == nil && info.IsDir() {
		_ = os.RemoveAll(link)
	}
	if err := os.Rename(tmp, link); err != nil {
		_ = os.Remove(tmp)

		return err
	}

	return nil
}

// writeAtomic writes the file with a rename, and skips a write that changes
// nothing.
func writeAtomic(path string, data []byte) error {
	if cur, err := os.ReadFile(path); err == nil && bytes.Equal(cur, data) {
		return nil
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".write-")
	if err != nil {
		return err
	}
	_, werr := tmp.Write(data)
	cerr := tmp.Close()
	if err := errors.Join(werr, cerr); err != nil {
		_ = os.Remove(tmp.Name())

		return err
	}
	if err := os.Chmod(tmp.Name(), 0o644); err != nil {
		_ = os.Remove(tmp.Name())

		return err
	}
	if err := os.Rename(tmp.Name(), path); err != nil {
		_ = os.Remove(tmp.Name())

		return err
	}

	return nil
}
