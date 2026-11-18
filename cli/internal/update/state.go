package update

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
	"sync"
)

const stateFile = "update.json"

// State is what the updater keeps between two runs: the versions it must not
// install, and the binary it staged for each version.
type State struct {
	Skips  []string          `json:"skip,omitempty"`
	Staged map[string]string `json:"staged,omitempty"`
	// DefaultOff lists the rule files, each an absolute resolved path, that an
	// image which takes a missing autoUpdate key as off has started with.
	DefaultOff []string `json:"defaultOff,omitempty"`
	// AutoUpdatePending lists the rule files where that image could not add
	// autoUpdate: true. A start of one tries again.
	AutoUpdatePending []string `json:"autoUpdatePending,omitempty"`
	// Revoked lists the fingerprints of release keys that the server revoked. It
	// only grows, because a server can only take trust away.
	Revoked []string `json:"revoked,omitempty"`
	// Signers names, for each staged version, the fingerprint of the key that
	// signed its release.
	Signers map[string]string `json:"signers,omitempty"`
}

// stateMu keeps two saves of this process in order. Save adds the revoked keys
// of the file on disk, so no save drops one.
var stateMu sync.Mutex

// LoadState reads update.json in dir. A missing file is an empty state.
func LoadState(dir string) (*State, error) {
	s := &State{Staged: map[string]string{}}
	data, err := os.ReadFile(filepath.Join(dir, stateFile))
	if errors.Is(err, fs.ErrNotExist) {
		return s, nil
	}
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(data, s); err != nil {
		return nil, fmt.Errorf("read %s: %w", stateFile, err)
	}
	if s.Staged == nil {
		s.Staged = map[string]string{}
	}
	if s.Signers == nil {
		s.Signers = map[string]string{}
	}

	return s, nil
}

// Save writes update.json in dir through a rename, so a crash leaves the old
// file or the new one.
func (s *State) Save(dir string) error {
	stateMu.Lock()
	defer stateMu.Unlock()

	return s.save(dir)
}

func (s *State) save(dir string) error {
	if onDisk, err := LoadState(dir); err == nil {
		s.addRevoked(onDisk.Revoked)
	}
	data, err := json.Marshal(s)
	if err != nil {
		return err
	}

	return WriteFile(filepath.Join(dir, stateFile), data, 0o600)
}

// AddRevoked adds fingerprints to the revoked set in update.json of dir and
// reports whether the set grew. It reads the file first, so it keeps what other
// writers added. It also holds the keys in memory for the life of the process,
// so LoadRevoked and StagedRevoked enforce them when the file cannot be read or
// saved.
func AddRevoked(dir string, fingerprints []string) (bool, error) {
	hold(dir, fingerprints)
	stateMu.Lock()
	defer stateMu.Unlock()
	st, err := LoadState(dir)
	if err != nil {
		return false, err
	}
	if !st.addRevoked(fingerprints) {
		return false, nil
	}

	return true, st.save(dir)
}

// Revoke adds fingerprints to the in-memory revoked set and reports whether it
// grew. It saves nothing.
func (s *State) Revoke(fingerprints []string) bool { return s.addRevoked(fingerprints) }

// StagedRevoked reports whether the staged binary of version names a signer
// that update.json of dir now revokes. It reads the file each time.
func StagedRevoked(dir, version string) bool {
	st, err := LoadState(dir)
	if err != nil {
		return false
	}
	signer, ok := st.Signers[version]

	return ok && slices.Contains(mergeKeys(st.Revoked, heldKeys(dir)), signer)
}

var (
	heldMu    sync.Mutex
	heldByDir = map[string][]string{}
)

// hold remembers revoked keys for dir in this process.
func hold(dir string, fingerprints []string) {
	heldMu.Lock()
	defer heldMu.Unlock()
	heldByDir[dir] = mergeKeys(heldByDir[dir], fingerprints)
}

func heldKeys(dir string) []string {
	heldMu.Lock()
	defer heldMu.Unlock()

	return slices.Clone(heldByDir[dir])
}

// mergeKeys returns a with the keys of b that it lacks, ignoring empty ones.
func mergeKeys(a, b []string) []string {
	out := slices.Clone(a)
	for _, f := range b {
		if f != "" && !slices.Contains(out, f) {
			out = append(out, f)
		}
	}

	return out
}

func (s *State) addRevoked(fingerprints []string) bool {
	grew := false
	for _, f := range fingerprints {
		if f != "" && !slices.Contains(s.Revoked, f) {
			s.Revoked = append(s.Revoked, f)
			grew = true
		}
	}

	return grew
}

// Skip adds version, as X.Y.Z, to the versions the updater must not install.
func (s *State) Skip(version string) {
	if !s.Skipped(version) {
		s.Skips = append(s.Skips, version)
	}
}

func (s *State) Skipped(version string) bool {
	return slices.Contains(s.Skips, version)
}

// SkipSet is the skip list in the shape Pick reads.
func (s *State) SkipSet() map[string]bool {
	set := map[string]bool{}
	for _, v := range s.Skips {
		set[v] = true
	}

	return set
}

// StagePath is where the binary of version waits for its handover.
func StagePath(dir, version string) string {
	return filepath.Join(dir, "versions", version, "loupe")
}

// WriteFile writes data to path through a temporary file in the same
// directory and a rename, and creates the directory.
func WriteFile(path string, data []byte, mode os.FileMode) error {
	return ReplaceFile(path, data, mode, nil)
}

// ReplaceFile is WriteFile with a check that runs after the temporary file is
// complete and just before the rename. An error from verify leaves path
// unchanged. A nil verify checks nothing.
func ReplaceFile(path string, data []byte, mode os.FileMode, verify func() error) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+"-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()

		return err
	}
	if err := tmp.Chmod(mode); err != nil {
		tmp.Close()

		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if verify != nil {
		if err := verify(); err != nil {
			return err
		}
	}

	return os.Rename(tmp.Name(), path)
}
