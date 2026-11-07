// Package profile checks the repository profile, .loupe/lifecycle.md. The file
// holds no copy of a repository rule. The init agent looks for blockers and
// writes nothing to it, so a missing file is as complete as any other.
package profile

import (
	"errors"
	"os"
	"path/filepath"
)

// Path is the profile, relative to the repository root.
const Path = ".loupe/lifecycle.md"

// Status says what init knows of the profile of a repository.
type Status struct {
	// Missing is true when the file does not exist.
	Missing bool
}

// Complete reports whether the file needs no agent. It is always true, because
// the file requires no section and no value.
func (s Status) Complete() bool {
	return true
}

// Check reads the profile in dir.
func Check(dir string) (Status, error) {
	_, err := os.Stat(filepath.Join(dir, Path))
	if errors.Is(err, os.ErrNotExist) {
		return Status{Missing: true}, nil
	}
	if err != nil {
		return Status{}, err
	}

	return Status{}, nil
}
