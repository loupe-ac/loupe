package cmd

import (
	"bufio"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"
	"time"

	"github.com/spf13/cobra"
)

const (
	runIDEnv         = "LOUPE_RUN_ID"
	controlSocketEnv = "LOUPE_CONTROL_SOCKET"
)

// credentialRetryWindow and credentialRetryInterval say how long a worker
// waits for the bridge to listen again after an update handover.
var (
	credentialRetryWindow   = 5 * time.Second
	credentialRetryInterval = 200 * time.Millisecond
)

func newCredentialCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "credential [get]",
		Short: "Print a GitHub token for the run of this worker",
		Long: "Asks the bridge that started this worker for a GitHub token. With no argument it prints " +
			"the bare token and a newline. With `get` it speaks the git credential helper protocol, " +
			"for `credential.helper`, and answers only for https://github.com. `store` and `erase` do nothing.\n\n" +
			"The bridge sets LOUPE_RUN_ID and LOUPE_CONTROL_SOCKET in the environment of a worker that gets " +
			"credentials from it. Run this command each time you need a token, and never store the token. " +
			"The command exits 1 with one line on stderr when it gets no token.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			action := ""
			if len(args) == 1 {
				action = args[0]
			}
			switch action {
			case "store", "erase":
				return nil
			case "", "get":
			default:
				return fmt.Errorf("unknown action %q: use get, store or erase", action)
			}

			if action == "get" && !wantsGitHub(cmd.InOrStdin()) {
				return nil
			}
			res, err := fetchCredential()
			if err != nil {
				return err
			}
			if action == "get" {
				_, err = fmt.Fprintf(cmd.OutOrStdout(), "username=%s\npassword=%s\n\n", res.Login, res.Token)

				return err
			}
			_, err = fmt.Fprintln(cmd.OutOrStdout(), res.Token)

			return err
		},
	}
}

// wantsGitHub reads the key=value lines of the git credential protocol up to a
// blank line and says whether they ask for https://github.com.
func wantsGitHub(in io.Reader) bool {
	var protocol, host string
	scanner := bufio.NewScanner(in)
	for scanner.Scan() {
		line := strings.TrimRight(scanner.Text(), "\r")
		if line == "" {
			break
		}
		key, value, _ := strings.Cut(line, "=")
		switch key {
		case "protocol":
			protocol = value
		case "host":
			host = value
		}
	}

	return protocol == "https" && (host == "" || strings.EqualFold(host, "github.com"))
}

// fetchCredential asks the bridge for the credentials of this run. Its errors
// hold no token.
func fetchCredential() (credentialResult, error) {
	runID, sock := os.Getenv(runIDEnv), os.Getenv(controlSocketEnv)
	if runID == "" || sock == "" {
		return credentialResult{}, errors.New("this process is not a worker of a bridge: " + runIDEnv + " or " + controlSocketEnv + " is not set")
	}
	res, err := requestCredential(sock, runID, credentialRetryWindow, credentialRetryInterval)
	if err != nil {
		return credentialResult{}, fmt.Errorf("cannot reach the bridge: %w", err)
	}
	if !res.OK || res.Token == "" {
		return credentialResult{}, errors.New("the bridge gave no GitHub token: " + oneLine(res.Problem))
	}

	return res, nil
}

// oneLine puts s on one line, so an error stays one line of stderr.
func oneLine(s string) string {
	return strings.Join(strings.Fields(s), " ")
}
