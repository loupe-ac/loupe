package skills

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

// homesDir is the folder in the store that holds one file per Codex session,
// which names the Codex home the session runs on. An interactive session
// outlives its launcher and can outlive the bridge, so the bridge keeps the
// home on disk.
const homesDir = ".homes"

// homeLocksDir is the folder in the store that holds one lock file per Codex
// home. A lock belongs to the process that holds it and ends with that process,
// so a bridge and `loupe init` share it, and a crash leaves no lock behind.
const homeLocksDir = ".home-locks"

// homeHoldPoll is how often a worker start tries the lock of a home again.
const homeHoldPoll = 100 * time.Millisecond

// HomeLock is a lock on one Codex home. A worker holds it shared from its start
// to its end. A plugin install holds it alone, across its check and its install.
type HomeLock struct {
	file *os.File
}

// Release ends the lock. Closing the file drops it.
func (l *HomeLock) Release() {
	if l != nil {
		_ = l.file.Close()
	}
}

// ReserveHome takes the home for an install. It reports false when a worker or
// another install holds the home, and it does not wait.
func ReserveHome(configDir, home string) (*HomeLock, bool, error) {
	f, err := openHomeLock(configDir, home)
	if err != nil {
		return nil, false, err
	}
	ok, err := tryLock(f, syscall.LOCK_EX)
	if err != nil || !ok {
		_ = f.Close()

		return nil, false, err
	}

	return &HomeLock{file: f}, true, nil
}

// HoldHome takes the home for a worker. It waits while an install holds the home.
func HoldHome(ctx context.Context, configDir, home string) (*HomeLock, error) {
	f, err := openHomeLock(configDir, home)
	if err != nil {
		return nil, err
	}
	for {
		ok, err := tryLock(f, syscall.LOCK_SH)
		if err != nil {
			_ = f.Close()

			return nil, err
		}
		if ok {
			return &HomeLock{file: f}, nil
		}
		select {
		case <-ctx.Done():
			_ = f.Close()

			return nil, ctx.Err()
		case <-time.After(homeHoldPoll):
		}
	}
}

// openHomeLock opens the lock file of a home. A home path can hold any character,
// so its hash names the file.
func openHomeLock(configDir, home string) (*os.File, error) {
	dir := filepath.Join(StoreDir(configDir), homeLocksDir)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	sum := sha256.Sum256([]byte(filepath.Clean(home)))

	return os.OpenFile(filepath.Join(dir, hex.EncodeToString(sum[:])), os.O_CREATE|os.O_RDWR, 0o600)
}

// tryLock takes the lock on this open file, and reports false when another open
// file holds a lock that blocks it.
func tryLock(f *os.File, how int) (bool, error) {
	for {
		err := syscall.Flock(int(f.Fd()), how|syscall.LOCK_NB)
		switch {
		case err == nil:
			return true, nil
		case errors.Is(err, syscall.EWOULDBLOCK):
			return false, nil
		case !errors.Is(err, syscall.EINTR):
			return false, err
		}
	}
}

// SessionHome is the Codex home of a session and the time its file was written.
type SessionHome struct {
	Home string
	At   time.Time
}

// WriteHome keeps the Codex home that a session runs on.
func WriteHome(configDir, session, home string) error {
	if session == "" || strings.HasPrefix(session, ".") || filepath.Base(session) != session {
		return &os.PathError{Op: "record the home of session", Path: session, Err: os.ErrInvalid}
	}
	dir := filepath.Join(StoreDir(configDir), homesDir)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}

	return os.WriteFile(filepath.Join(dir, session), []byte(home), 0o600)
}

// Homes reads the Codex home of each session that WriteHome kept.
func Homes(configDir string) map[string]SessionHome {
	dir := filepath.Join(StoreDir(configDir), homesDir)
	entries, _ := os.ReadDir(dir)
	homes := make(map[string]SessionHome, len(entries))
	for _, e := range entries {
		info, err := e.Info()
		if err != nil || !info.Mode().IsRegular() {
			continue
		}
		if data, err := os.ReadFile(filepath.Join(dir, e.Name())); err == nil {
			homes[e.Name()] = SessionHome{Home: string(data), At: info.ModTime()}
		}
	}

	return homes
}

// pruneHomes deletes each home file older than KeepAfterReplace, unless live
// says its session still runs.
func pruneHomes(configDir string, now time.Time, live func(session string) bool) {
	for session, h := range Homes(configDir) {
		if now.Sub(h.At) < KeepAfterReplace || (live != nil && live(session)) {
			continue
		}
		_ = os.Remove(filepath.Join(StoreDir(configDir), homesDir, session))
	}
}
