package projectfile

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
)

// LinksName is the file in a setup folder that holds the project links of that
// setup.
const LinksName = "projects.json"

type linksFile struct {
	Links map[string]string `json:"links"`
}

// LinkKey names the repository of dir: the absolute git common directory, so
// every folder and worktree of one repository share a key. A folder outside
// git is its own key. It runs git once, so call it once per start.
func LinkKey(dir string) string {
	abs, err := filepath.Abs(dir)
	if err != nil {
		abs = dir
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	out, err := exec.CommandContext(ctx, "git", "-C", abs, "rev-parse", "--git-common-dir").Output()
	key := abs
	if common := strings.TrimSpace(string(out)); err == nil && common != "" {
		if !filepath.IsAbs(common) {
			common = filepath.Join(abs, common)
		}
		key = common
	}
	if resolved, err := filepath.EvalSymlinks(key); err == nil {
		key = resolved
	}

	return filepath.Clean(key)
}

func readLinks(setupDir string) (linksFile, error) {
	var f linksFile
	path := filepath.Join(setupDir, LinksName)
	b, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return f, nil
	}
	if err != nil {
		return f, fmt.Errorf("%s: %w", path, err)
	}
	if len(strings.TrimSpace(string(b))) == 0 {
		return f, nil
	}
	if err := json.Unmarshal(b, &f); err != nil {
		return f, fmt.Errorf("%s: %w", path, err)
	}

	return f, nil
}

// LoadLink gives the project that the setup links to key, or "" when it links
// none.
func LoadLink(setupDir, key string) (string, error) {
	f, err := readLinks(setupDir)
	if err != nil {
		return "", err
	}

	return f.Links[key], nil
}

// WriteLink links key to projectID in the setup, replacing a link that
// exists. It writes through a temporary file and a rename.
func WriteLink(setupDir, key, projectID string) error {
	if err := Valid(projectID); err != nil {
		return err
	}
	f, err := readLinks(setupDir)
	if err != nil {
		return err
	}
	if f.Links == nil {
		f.Links = map[string]string{}
	}
	f.Links[key] = strings.ToLower(projectID)
	b, err := json.MarshalIndent(f, "", "  ")
	if err != nil {
		return err
	}
	if err := os.MkdirAll(setupDir, 0o700); err != nil {
		return fmt.Errorf("create the setup folder: %w", err)
	}

	tmp, err := os.CreateTemp(setupDir, LinksName+".*")
	if err != nil {
		return fmt.Errorf("write %s: %w", LinksName, err)
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(append(b, '\n')); err != nil {
		tmp.Close()

		return fmt.Errorf("write %s: %w", LinksName, err)
	}
	if err := tmp.Close(); err != nil {
		return fmt.Errorf("write %s: %w", LinksName, err)
	}
	if err := os.Rename(tmp.Name(), filepath.Join(setupDir, LinksName)); err != nil {
		return fmt.Errorf("write %s: %w", LinksName, err)
	}

	return nil
}
