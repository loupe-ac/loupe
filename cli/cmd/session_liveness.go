package cmd

import (
	"context"
	"os"
	"os/exec"
	"slices"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/loupe-ac/loupe/cli/internal/transcript"
)

// psTimeout bounds one process listing, which runs inside a heartbeat.
const psTimeout = 5 * time.Second

// sessionFlags are the claude flags that name a session by id.
var sessionFlags = []string{"--session-id", "--resume"}

// sessionLiveness reads how each watched interactive session stands. The
// fields are injectable so a test needs no process list and no transcript.
type sessionLiveness struct {
	// processes returns the command lines of all processes, one for each line.
	processes func(context.Context) (string, error)
	// transcriptAt returns the modification time of the transcript of a
	// session, and false when the bridge finds none.
	transcriptAt func(sessionID string) (time.Time, bool)
}

// readings returns one reading for each id, in order. It lists the processes
// once. Alive is nil when the listing fails or is empty, because the bridge
// cannot tell then whether the session runs.
func (l sessionLiveness) readings(ctx context.Context, ids []string) []api.InteractiveSessionReading {
	if len(ids) == 0 {
		return nil
	}
	var lines []string
	listed := false
	if l.processes != nil {
		if out, err := l.processes(ctx); err == nil && strings.TrimSpace(out) != "" {
			lines, listed = strings.Split(out, "\n"), true
		}
	}

	out := make([]api.InteractiveSessionReading, 0, len(ids))
	for _, id := range ids {
		r := api.InteractiveSessionReading{SessionID: id}
		if listed {
			alive := slices.ContainsFunc(lines, func(line string) bool { return namesSession(line, id) })
			r.Alive = &alive
		}
		if l.transcriptAt != nil {
			if at, ok := l.transcriptAt(id); ok {
				r.TranscriptAt = &at
			}
		}
		out = append(out, r)
	}

	return out
}

// namesSession reports whether a command line carries a session flag with
// exactly this id, as "--session-id <id>" or "--session-id=<id>".
func namesSession(line, id string) bool {
	fields := strings.Fields(line)
	for i, f := range fields {
		for _, flag := range sessionFlags {
			if f == flag && i+1 < len(fields) && fields[i+1] == id {
				return true
			}
			if f == flag+"="+id {
				return true
			}
		}
	}

	return false
}

// listProcesses runs ps once. "-axo pid=,args=" works the same on macOS and
// Linux.
func listProcesses(ctx context.Context) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, psTimeout)
	defer cancel()
	out, err := exec.CommandContext(ctx, "ps", "-axo", "pid=,args=").Output()

	return string(out), err
}

// newSessionLiveness reads the real process list, and the transcripts in the
// Claude Code config folder of the bridge and of each account of the rules.
func newSessionLiveness(set func() *rules.Set) sessionLiveness {
	return sessionLiveness{
		processes: listProcesses,
		transcriptAt: func(id string) (time.Time, bool) {
			return newestTranscript(claudeConfigDirs(set()), id)
		},
	}
}

// claudeConfigDirs lists the folders a Claude Code session can live in.
func claudeConfigDirs(set *rules.Set) []string {
	var dirs []string
	if dir, err := transcript.ConfigDir(); err == nil {
		dirs = append(dirs, dir)
	}
	if set == nil {
		return dirs
	}
	for _, name := range set.DeclaredAccounts() {
		run, ok := set.Account(name, "")
		if !ok || (run.Harness != "" && run.Harness != rules.HarnessClaudeCode) || run.ConfigDir == "" || slices.Contains(dirs, run.ConfigDir) {
			continue
		}
		dirs = append(dirs, run.ConfigDir)
	}

	return dirs
}

// newestTranscript returns the latest modification time of the transcripts of
// a session across dirs.
func newestTranscript(dirs []string, id string) (time.Time, bool) {
	var newest time.Time
	found := false
	for _, dir := range dirs {
		path, err := transcript.Find(dir, id)
		if err != nil {
			continue
		}
		info, err := os.Stat(path)
		if err != nil {
			continue
		}
		if !found || info.ModTime().After(newest) {
			newest, found = info.ModTime(), true
		}
	}

	return newest, found
}
