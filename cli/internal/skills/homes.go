package skills

import (
	"os"
	"path/filepath"
	"strings"
	"time"
)

// homesDir is the folder in the store that holds one file per Codex session,
// which names the Codex home the session runs on. An interactive session
// outlives its launcher and can outlive the bridge, so the bridge keeps the
// home on disk.
const homesDir = ".homes"

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
