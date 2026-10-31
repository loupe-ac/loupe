// Package harness is what the bridge needs from the coding agent that runs a
// worker: how to start and resume one, how to read a finished run, and where
// its sessions are.
package harness

import (
	"context"
	"encoding/json"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/stream"
	"github.com/loupe-ac/loupe/cli/internal/transcript"
)

// Harness is one coding agent the bridge can run.
type Harness interface {
	Name() string
	// Program is the binary the bridge looks up on PATH.
	Program() string
	// Worker starts a new session, and Resume continues spec's session.
	Worker(spec Spec) Command
	Resume(spec Spec) Command
	// Interactive is the body of the launch script that runs program in a
	// terminal.
	Interactive(program string, spec Spec) string
	// ReadRun reads a finished run from the files in its run directory.
	ReadRun(dir string, run RunInfo) Output
	// SessionUsage is what the session spent at or after from, and before to
	// when to is set.
	SessionUsage(sessionID string, from, to time.Time) (transcript.Usage, error)
	// SessionTotal is what the whole session spent before a resume.
	SessionTotal(sessionID string) (transcript.Usage, error)
	// StartDir is the folder the session started in, and "" with no error
	// when this machine holds no record of the session.
	StartDir(sessionID string) (string, error)
	// HasSession fails when this machine holds no record of the session.
	HasSession(sessionID string) error
	// Check lists what keeps the account from running a worker in each
	// project of spec, and is nil when the account is ready.
	Check(ctx context.Context, spec CheckSpec) []Problem
}

// LaunchRecorder is a harness that cannot name its session when an interactive
// launch starts. The bridge records the folder and the time of the launch, and
// the harness finds the session from them when it is asked.
type LaunchRecorder interface {
	RecordLaunch(runID, dir string, at time.Time) error
	// ForgetLaunch drops the record of a launch that failed.
	ForgetLaunch(runID string)
}

// RunInfo is what the bridge knows about a run when it reads it. SessionID is
// the id the bridge gave the run, and Model is the model it asked for. Since
// is a time before the run started, and zero when the bridge does not know
// it. A session file can hold the earlier runs of a resumed session.
type RunInfo struct {
	SessionID string
	Model     string
	Since     time.Time
}

// CostSourceOpenRouter is the cost source of a run that went through
// OpenRouter.
const CostSourceOpenRouter = "openrouter"

// CheckSpec is one account to check. Env holds what the account adds to the
// environment, and Projects maps each project slug to its folder.
type CheckSpec struct {
	Account   string
	ConfigDir string
	Env       []string
	Projects  map[string]string
}

// Problem is one thing that keeps an account from running. Reason is a short
// fixed phrase that leaves the machine, so it names no path and no identity.
// Detail goes to the bridge log alone.
type Problem struct {
	Reason string
	Detail string
}

// Spec is one run. An empty Model, Effort, PermissionMode or Schema passes no
// flag.
// Env is the environment of a worker, and the variables a launch script sets.
type Spec struct {
	Dir            string
	Model          string
	Effort         string
	PermissionMode string
	Schema         string
	SessionID      string
	Prompt         string
	Env            []string
	// RunID is the id the bridge gives the run. It is the run key that Loupe
	// stores, and a harness that routes through OpenRouter sends it there.
	RunID string
	// RunDir is the run directory, which holds the files of a worker.
	RunDir string
}

// Command is the argv after the program, and the environment of the process.
// Files maps an absolute path to the content the bridge writes there, with
// mode 0600, before the process starts.
type Command struct {
	Args  []string
	Env   []string
	Files map[string]string
}

// Output is what a worker left. Decoded says the run left a valid document,
// and Result can hold text of one that did not decode. CallsRead says the
// harness read the calls of the run, so Calls and Timing are what the run
// held. A nil PeakContextTokens is unknown. ReadErr says why the output of
// the run did not read to its end.
type Output struct {
	Decoded           bool
	StructuredOutput  json.RawMessage
	Result            string
	Usage             transcript.Usage
	CallsRead         bool
	Calls             []stream.Call
	Timing            stream.Timing
	PeakContextTokens *int64
	// HarnessVersion is the version of the harness binary that ran, and
	// SkillVersion the version of the Loupe plugin it loaded. Either is empty
	// when the run did not say.
	HarnessVersion string
	SkillVersion   string
	// CostSource names who prices the run: "openrouter" when the run went
	// through OpenRouter and Loupe reads the billed cost, and "" when the
	// bridge prices the usage.
	CostSource string
	// HarnessEvents counts the events of the run by kind. A nil map says the
	// harness could not read the files, and an empty one says it read them and
	// found no event.
	HarnessEvents map[string]int64
	ReadErr       error
}
