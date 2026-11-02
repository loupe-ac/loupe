package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/spf13/cobra"
)

// sessionEndTimeout bounds the close call. The hook script runs this command
// in the background, so the limit only stops a hung process.
const sessionEndTimeout = 20 * time.Second

// maxHookInput caps the hook input the command reads.
const maxHookInput = 1 << 20

func newSessionCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "session",
		Short: "Tell Loupe about an interactive session",
	}
	cmd.AddCommand(newSessionEndCmd(), newSessionRemindCmd())

	return cmd
}

func newSessionEndCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "end",
		Short: "Close the open runs of the Claude Code session that just ended",
		Long: "Reads the JSON that a Claude Code SessionEnd hook writes to stdin, takes its session_id, " +
			"and asks Loupe to close the open interactive runs of that session. The Loupe plugin " +
			"installs the hook.\n\n" +
			"The command uses your stored login. It reads no project file. It prints nothing on " +
			"success and exits 1 with the reason on stderr when it fails.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			id, err := sessionIDOfHook(cmd.InOrStdin())
			if err != nil {
				return err
			}
			ctx, cancel := context.WithTimeout(cmd.Context(), sessionEndTimeout)
			defer cancel()
			_, err = closeInteractiveSession(ctx, id)

			return err
		},
	}
}

// sessionIDOfHook reads the session_id of a hook input.
func sessionIDOfHook(in io.Reader) (string, error) {
	var input struct {
		SessionID string `json:"session_id"`
	}
	if err := json.NewDecoder(io.LimitReader(in, maxHookInput)).Decode(&input); err != nil {
		return "", fmt.Errorf("read the hook input: %w", err)
	}
	if input.SessionID == "" {
		return "", errors.New("the hook input has no session_id")
	}

	return input.SessionID, nil
}

// closeInteractiveSession signs in from the stored login and closes the open
// runs of the session. A test replaces it.
var closeInteractiveSession = func(ctx context.Context, sessionID string) (int, error) {
	cfg, err := config.Load()
	if err != nil {
		if errors.Is(err, config.ErrNotLoggedIn) {
			return 0, errors.New("you are not signed in: run `loupe login`")
		}

		return 0, err
	}
	return apiClient(cfg).CloseInteractiveSession(ctx, sessionID)
}
