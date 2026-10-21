package cmd

import (
	"os"
	"path/filepath"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/setupbuild"
)

// setupPin is the folder of the pinned copy of the checked build that this
// process hands to its children, or "" when the setup is not built.
var setupPin string

// makeSetupPin pins the checked build of a built setup. A process that runs
// from a pin already hands that same pin on.
func makeSetupPin() (string, error) {
	setup := config.Current()
	if setup.Source == config.SourceDefault {
		return "", nil
	}
	if exe, err := os.Executable(); err == nil && setupbuild.InPin(setup.Dir, exe) {
		setupPin = filepath.Dir(exe)

		return setupPin, nil
	}
	dir, err := setupbuild.Pin(setup.Dir)
	if err != nil {
		return "", err
	}
	setupPin = dir

	return dir, nil
}

// pinFirst puts the pin folder in front of a PATH value.
func pinFirst(path string) string {
	if setupPin == "" {
		return path
	}
	if path == "" {
		return setupPin
	}

	return setupPin + string(os.PathListSeparator) + path
}
