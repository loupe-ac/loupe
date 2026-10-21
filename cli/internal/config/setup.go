package config

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
)

// Source says what chose a setup.
type Source string

const (
	SourceFlag    Source = "-c"
	SourceEnv     Source = "LOUPE_CONFIG"
	SourceDefault Source = "the default"
)

// EnvVar names the variable that carries the setup to child processes.
const EnvVar = "LOUPE_CONFIG"

// Setup is the folder that holds the login, the rules and every file of one
// Loupe setup.
type Setup struct {
	Dir    string
	Source Source
}

var (
	currentMu sync.Mutex
	current   *Setup
)

// DefaultDir is the folder of the default setup, in the OS config directory.
func DefaultDir() (string, error) {
	d, err := os.UserConfigDir()
	if err != nil {
		return "", fmt.Errorf("locate config dir: %w", err)
	}

	return filepath.Join(d, "loupe"), nil
}

// Resolve picks the setup from the flag value, else LOUPE_CONFIG, else the OS
// default, and makes it the current setup. A relative path and a leading ~
// become absolute.
func Resolve(flag string) (Setup, error) {
	s, err := resolve(flag)
	if err != nil {
		return Setup{}, err
	}

	currentMu.Lock()
	defer currentMu.Unlock()
	if s.Source == SourceDefault {
		current = nil
	} else {
		current = &s
	}

	return s, nil
}

func resolve(flag string) (Setup, error) {
	raw, source := flag, SourceFlag
	if raw == "" {
		raw, source = os.Getenv(EnvVar), SourceEnv
	}
	if raw == "" {
		d, err := DefaultDir()
		if err != nil {
			return Setup{}, err
		}

		return Setup{Dir: d, Source: SourceDefault}, nil
	}

	dir, err := absolute(raw)
	if err != nil {
		return Setup{}, fmt.Errorf("resolve the setup folder %q: %w", raw, err)
	}

	return Setup{Dir: dir, Source: source}, nil
}

func absolute(p string) (string, error) {
	if p == "~" || strings.HasPrefix(p, "~/") {
		home, err := os.UserHomeDir()
		if err != nil {
			return "", err
		}
		p = filepath.Join(home, strings.TrimPrefix(p, "~"))
	}

	return filepath.Abs(p)
}

// Current is the setup that Resolve chose. Before any Resolve it reads
// LOUPE_CONFIG, so a child process follows its parent.
func Current() Setup {
	currentMu.Lock()
	s := current
	currentMu.Unlock()
	if s != nil {
		return *s
	}
	if os.Getenv(EnvVar) != "" {
		if s, err := resolve(""); err == nil {
			return s
		}
	}
	d, _ := DefaultDir()

	return Setup{Dir: d, Source: SourceDefault}
}

// Dir is the directory that holds config.json, and rules.yaml beside it.
func Dir() (string, error) {
	s := Current()
	if s.Dir == "" {
		return DefaultDir()
	}

	return s.Dir, nil
}

// TempDir is the folder for temporary files. A setup other than the default
// keeps them in its own tmp folder, mode 0700. The path comes back with any
// error, so a caller can still name it.
func TempDir() (string, error) {
	s := Current()
	if s.Source == SourceDefault {
		return os.TempDir(), nil
	}
	d := filepath.Join(s.Dir, "tmp")
	if err := os.MkdirAll(d, 0o700); err != nil {
		return d, fmt.Errorf("create the setup temp folder: %w", err)
	}

	return d, nil
}
