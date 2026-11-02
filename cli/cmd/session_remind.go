package cmd

import (
	"context"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
	"github.com/spf13/cobra"
)

// remindTimeout bounds the whole check, the card run request included.
const remindTimeout = 2 * time.Second

// remindSilence is how long a session stays quiet after a found open run or a
// reminder.
const remindSilence = 10 * time.Minute

const lifecycleTemplate = "lifecycle"

const remindText = "This session edits files or opens a pull request, and no card run is open on this " +
	"Lifecycle project. If this is work on a card, find or create the card, then call card_run_open " +
	"with the Implementation status before you go on (see the loupe-lifecycle-session skill). " +
	"If this is not card work, ignore this reminder."

var pullRequestCreate = regexp.MustCompile(`\bgh\s+pr\s+create\b`)

// sessionIDPattern limits the session ids that name a state file.
var sessionIDPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$`)

func newSessionRemindCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "remind",
		Short: "Remind a session to open a card run before it edits files",
		Long: "Reads the JSON that a Claude Code PreToolUse hook writes to stdin. When the session works " +
			"in a project that .loupe.yaml or the setup link names, the project uses the Lifecycle template, " +
			"and the session has no open card run, it prints a reminder as hook context. The Loupe plugin " +
			"installs the hook.\n\n" +
			"The command never blocks a tool call. It prints nothing and exits 0 when it is unsure, " +
			"including on any error.",
		Args:          cobra.NoArgs,
		SilenceUsage:  true,
		SilenceErrors: true,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx, cancel := context.WithTimeout(cmd.Context(), remindTimeout)
			defer cancel()
			if text := remindOutput(ctx, cmd.InOrStdin()); text != "" {
				io.WriteString(cmd.OutOrStdout(), text)
			}

			return nil
		},
	}
}

// remindState is what the command remembers about one session.
type remindState struct {
	Project string `json:"project"`
	// Template is the template key the server named, or "" when it named none.
	Template    string `json:"template"`
	SilentUntil int64  `json:"silentUntil"`
}

// remindNow, fetchCardRun and remindStateDir are replaced by tests.
var (
	remindNow = time.Now

	fetchCardRun = func(ctx context.Context, sessionID, projectID string) (api.CardRun, error) {
		cfg, err := config.Load()
		if err != nil {
			return api.CardRun{}, err
		}

		return apiClient(cfg).SessionCardRun(ctx, sessionID, projectID)
	}

	remindStateDir = func() (string, error) {
		d, err := config.Dir()
		if err != nil {
			return "", err
		}

		return filepath.Join(d, "session-remind"), nil
	}
)

// remindOutput gives the hook output to print, or "" for none.
func remindOutput(ctx context.Context, in io.Reader) string {
	var input struct {
		SessionID string `json:"session_id"`
		ToolName  string `json:"tool_name"`
		ToolInput struct {
			Command string `json:"command"`
		} `json:"tool_input"`
	}
	if err := json.NewDecoder(io.LimitReader(in, maxHookInput)).Decode(&input); err != nil {
		return ""
	}
	if !sessionIDPattern.MatchString(input.SessionID) {
		return ""
	}
	forced := false
	if input.ToolName == "Bash" {
		if !pullRequestCreate.MatchString(input.ToolInput.Command) {
			return ""
		}
		forced = true
	}

	project := explicitProject()
	if project == "" {
		return ""
	}
	dir, err := remindStateDir()
	if err != nil {
		return ""
	}
	path := filepath.Join(dir, input.SessionID+".json")

	now := remindNow()
	state := readRemindState(path)
	if state.Project != project {
		state = remindState{Project: project}
	}
	if state.Template != "" && state.Template != lifecycleTemplate {
		return ""
	}
	if !forced && now.Unix() < state.SilentUntil {
		return ""
	}

	answer, err := fetchCardRun(ctx, input.SessionID, project)
	if err != nil {
		return ""
	}

	state.Template = answer.Template
	state.SilentUntil = 0
	remind := false
	switch {
	case answer.Template == "" || (answer.Template == lifecycleTemplate && answer.Open):
		state.SilentUntil = now.Add(remindSilence).Unix()
	case answer.Template == lifecycleTemplate:
		state.SilentUntil = now.Add(remindSilence).Unix()
		remind = true
	}
	writeRemindState(dir, path, state)
	if !remind {
		return ""
	}

	out, err := json.Marshal(map[string]any{"hookSpecificOutput": map[string]string{
		"hookEventName":     "PreToolUse",
		"additionalContext": remindText,
	}})
	if err != nil {
		return ""
	}

	return string(out) + "\n"
}

// explicitProject gives the project that the working directory names on
// purpose: the link of a non-default setup first, then .loupe.yaml. It gives
// "" when neither names one, because the server fallback to a single project
// is no proof that this folder is Loupe work.
func explicitProject() string {
	dir, err := os.Getwd()
	if err != nil {
		return ""
	}
	if link, err := setupLink(dir); err != nil {
		return ""
	} else if link != "" {
		return link
	}
	file, err := projectfile.Load(dir)
	if err != nil {
		return ""
	}

	return file.Project
}

func readRemindState(path string) remindState {
	var s remindState
	b, err := os.ReadFile(path)
	if err != nil {
		return remindState{}
	}
	if err := json.Unmarshal(b, &s); err != nil {
		return remindState{}
	}

	return s
}

// writeRemindState replaces the state file through a temporary file and a
// rename. A failed write only costs a repeated check.
func writeRemindState(dir, path string, s remindState) {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return
	}
	b, err := json.Marshal(s)
	if err != nil {
		return
	}
	f, err := os.CreateTemp(dir, ".state-*")
	if err != nil {
		return
	}
	tmp := f.Name()
	_, werr := f.Write(b)
	cerr := f.Close()
	if werr != nil || cerr != nil || os.Chmod(tmp, 0o600) != nil || os.Rename(tmp, path) != nil {
		os.Remove(tmp)
	}
}
