// Package skills holds the starting copy of the Loupe skill packages that a
// release embeds in the binary.
package skills

import (
	"embed"
	"encoding/json"
	"fmt"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"strings"
)

// The bundle folder holds one plugin folder for each package. A development
// build holds none, and only the release job fills it.
//
//go:embed all:bundle
var bundle embed.FS

// manifestPath is the plugin manifest that names the version of a package.
const manifestPath = ".claude-plugin/plugin.json"

// executablesPath lists the executable files of a package, relative to it.
// bundle-skills.sh writes it, because embed.FS reports every file as 0444.
const executablesPath = ".loupe-executables"

// Install unpacks each embedded package into the store under configDir, as
// <configDir>/skills/<package>/<version>. It skips a version that the store
// already holds, and it returns the packages it wrote.
func Install(configDir string) ([]string, error) {
	return install(bundle, "bundle", configDir)
}

func install(fsys fs.FS, root, configDir string) ([]string, error) {
	entries, err := fs.ReadDir(fsys, root)
	if err != nil {
		return nil, err
	}

	var written []string
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}
		name := entry.Name()
		version, err := packageVersion(fsys, path.Join(root, name))
		if err != nil {
			return written, fmt.Errorf("package %s: %w", name, err)
		}
		dest := filepath.Join(configDir, "skills", name, version)
		if _, err := os.Stat(dest); err == nil {
			continue
		}
		if err := unpack(fsys, path.Join(root, name), dest); err != nil {
			return written, fmt.Errorf("package %s: %w", name, err)
		}
		written = append(written, name)
	}

	return written, nil
}

func packageVersion(fsys fs.FS, dir string) (string, error) {
	data, err := fs.ReadFile(fsys, path.Join(dir, manifestPath))
	if err != nil {
		return "", err
	}
	var manifest struct {
		Version string `json:"version"`
	}
	if err := json.Unmarshal(data, &manifest); err != nil {
		return "", err
	}
	if manifest.Version == "" {
		return "", fmt.Errorf("%s has no version", manifestPath)
	}

	return manifest.Version, nil
}

// unpack writes the folder to a temporary sibling and renames it into place,
// so a store never holds a half-written version.
func unpack(fsys fs.FS, src, dest string) error {
	parent := filepath.Dir(dest)
	if err := os.MkdirAll(parent, 0o755); err != nil {
		return err
	}
	tmp, err := os.MkdirTemp(parent, ".unpack-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(tmp)

	list, err := fs.ReadFile(fsys, path.Join(src, executablesPath))
	if err != nil {
		return err
	}
	executable := make(map[string]bool)
	for _, line := range strings.Split(string(list), "\n") {
		executable[line] = true
	}

	err = fs.WalkDir(fsys, src, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel := strings.TrimPrefix(p[len(src):], "/")
		if rel == executablesPath {
			return nil
		}
		target := filepath.Join(tmp, filepath.FromSlash(rel))
		if d.IsDir() {
			return os.MkdirAll(target, 0o755)
		}
		data, err := fs.ReadFile(fsys, p)
		if err != nil {
			return err
		}
		mode := os.FileMode(0o644)
		if executable[rel] {
			mode = 0o755
		}

		return os.WriteFile(target, data, mode)
	})
	if err != nil {
		return err
	}

	return os.Rename(tmp, dest)
}
