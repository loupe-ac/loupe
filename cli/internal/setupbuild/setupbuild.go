// Package setupbuild checks and pins the build of a setup. A setup is built
// when it holds its own bin/loupe or a build.json record. The recipe
// `just cli-setup` writes both, after it has checked the build.
package setupbuild

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

// RecordName is the record that the recipe writes beside bin/.
const RecordName = "build.json"

// Record is what the recipe knows about the build it installed.
type Record struct {
	Checkout string `json:"checkout"`
	Commit   string `json:"commit"`
	Dirty    bool   `json:"dirty"`
	Size     int64  `json:"size"`
	SHA256   string `json:"sha256"`
}

// BinPath is the binary of a built setup.
func BinPath(setupDir string) string { return filepath.Join(setupDir, "bin", "loupe") }

// RecordPath is the record of a built setup.
func RecordPath(setupDir string) string { return filepath.Join(setupDir, RecordName) }

func pinRoot(setupDir string) string { return filepath.Join(setupDir, "tmp", "pin") }

// Built reports whether the setup holds a binary or a record.
func Built(setupDir string) bool {
	for _, p := range []string{BinPath(setupDir), RecordPath(setupDir)} {
		if _, err := os.Lstat(p); err == nil {
			return true
		}
	}

	return false
}

// RefusedError says that the binary of a setup is not the build the recipe
// checked.
type RefusedError struct {
	SetupDir string
	Reason   string
}

func (e *RefusedError) Error() string {
	msg := BinPath(e.SetupDir) + " is not the build that just cli-setup checked; run just cli-setup " + e.SetupDir
	if e.Reason != "" {
		msg += " (" + e.Reason + ")"
	}

	return msg
}

// LoadRecord reads build.json.
func LoadRecord(setupDir string) (Record, error) {
	var r Record
	b, err := os.ReadFile(RecordPath(setupDir))
	if err != nil {
		return r, err
	}
	if err := json.Unmarshal(b, &r); err != nil {
		return r, err
	}

	return r, nil
}

// Check verifies bin/loupe against build.json: both exist, the size and the
// SHA-256 match, the current user owns the file, and no group or other user
// can write it.
func Check(setupDir string) (Record, error) {
	rec, err := LoadRecord(setupDir)
	if err != nil {
		return rec, &RefusedError{setupDir, "build.json: " + reasonOf(err)}
	}
	bin := BinPath(setupDir)
	f, err := os.Open(bin)
	if err != nil {
		return rec, &RefusedError{setupDir, reasonOf(err)}
	}
	defer f.Close()
	if err := checkOwner(f); err != nil {
		return rec, &RefusedError{setupDir, err.Error()}
	}
	h := sha256.New()
	n, err := io.Copy(h, f)
	if err != nil {
		return rec, &RefusedError{setupDir, reasonOf(err)}
	}
	if n != rec.Size || hex.EncodeToString(h.Sum(nil)) != rec.SHA256 {
		return rec, &RefusedError{setupDir, "the size or the hash differs from build.json"}
	}

	return rec, nil
}

func reasonOf(err error) string {
	if errors.Is(err, os.ErrNotExist) {
		return "missing"
	}

	return err.Error()
}

func checkOwner(f *os.File) error {
	info, err := f.Stat()
	if err != nil {
		return err
	}
	if !info.Mode().IsRegular() {
		return errors.New("not a regular file")
	}
	if st, ok := info.Sys().(*syscall.Stat_t); ok && int(st.Uid) != os.Getuid() {
		return errors.New("the current user does not own it")
	}
	if info.Mode().Perm()&0o022 != 0 {
		return errors.New("a group or another user can write it")
	}

	return nil
}

// InPin reports whether exe is a pinned copy of a setup.
func InPin(setupDir, exe string) bool {
	root := pinRoot(setupDir)
	for _, r := range []string{root, resolved(root)} {
		if strings.HasPrefix(exe, r+string(filepath.Separator)) {
			return true
		}
	}

	return false
}

func resolved(p string) string {
	if r, err := filepath.EvalSymlinks(p); err == nil {
		return r
	}

	return p
}

// Pin copies the checked binary to tmp/pin/<sha256>/loupe and returns that
// folder. The copy is hashed before it is renamed into place, so a binary that
// changes after the check never becomes a pin. A pin that exists is reused. A
// setup that is not built has no pin: the folder is "".
func Pin(setupDir string) (string, error) {
	if !Built(setupDir) {
		return "", nil
	}
	rec, err := LoadRecord(setupDir)
	if err != nil {
		return "", &RefusedError{setupDir, "build.json: " + reasonOf(err)}
	}
	dest := filepath.Join(pinRoot(setupDir), rec.SHA256)
	if info, err := os.Stat(filepath.Join(dest, "loupe")); err == nil && info.Size() == rec.Size {
		return dest, nil
	}

	src, err := os.Open(BinPath(setupDir))
	if err != nil {
		return "", &RefusedError{setupDir, reasonOf(err)}
	}
	defer src.Close()
	if err := checkOwner(src); err != nil {
		return "", &RefusedError{setupDir, err.Error()}
	}
	if err := os.MkdirAll(pinRoot(setupDir), 0o700); err != nil {
		return "", err
	}
	stage, err := os.MkdirTemp(pinRoot(setupDir), ".stage-")
	if err != nil {
		return "", err
	}
	defer os.RemoveAll(stage)
	copyPath := filepath.Join(stage, "loupe")
	out, err := os.OpenFile(copyPath, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o700)
	if err != nil {
		return "", err
	}
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(out, h), src)
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return "", err
	}
	if n != rec.Size || hex.EncodeToString(h.Sum(nil)) != rec.SHA256 {
		return "", &RefusedError{setupDir, "the size or the hash differs from build.json"}
	}
	if err := os.Chmod(copyPath, 0o500); err != nil {
		return "", err
	}
	if err := os.Mkdir(dest, 0o700); err != nil && !errors.Is(err, os.ErrExist) {
		return "", err
	}
	if err := os.Rename(copyPath, filepath.Join(dest, "loupe")); err != nil {
		return "", fmt.Errorf("install the pin: %w", err)
	}

	return dest, nil
}

// PinGrace is how long a pin that no run used stays. A session that an older
// bridge started keeps its pin on PATH after a restart on a new build.
const PinGrace = 7 * 24 * time.Hour

// TouchPin records that a run used the pin folder dir, so PrunePins keeps it.
func TouchPin(dir string) {
	now := time.Now()
	_ = os.Chtimes(dir, now, now)
}

// PrunePins removes the pins of other builds than keep, the folder of the pin
// the caller runs, when no run used them for PinGrace.
func PrunePins(setupDir, keep string) {
	entries, err := os.ReadDir(pinRoot(setupDir))
	if err != nil {
		return
	}
	for _, e := range entries {
		path := filepath.Join(pinRoot(setupDir), e.Name())
		if path == keep || strings.HasPrefix(e.Name(), ".stage-") {
			continue
		}
		if info, err := os.Stat(path); err != nil || time.Since(info.ModTime()) < PinGrace {
			continue
		}
		_ = os.Chmod(path, 0o700)
		_ = os.RemoveAll(path)
	}
}
