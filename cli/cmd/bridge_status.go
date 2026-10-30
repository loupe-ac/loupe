package cmd

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"slices"
	"strings"
	"time"

	"github.com/spf13/cobra"
)

// unknownStatusOp is what a bridge on an older CLI answers to the status op.
const unknownStatusOp = `unknown op "status"`

// bridgeReport is what one running bridge told, or why it told nothing.
type bridgeReport struct {
	sock   string
	status statusResult
	// older says the bridge runs a CLI that has no status op.
	older bool
	// problem says why a bridge that runs gave no status.
	problem string
}

// heading names the bridge: its name, else its rule file, else its socket.
func (b bridgeReport) heading() string {
	switch {
	case b.status.Name != "":
		return b.status.Name
	case b.status.RulesPath != "":
		return b.status.RulesPath
	}

	return b.sock
}

func (b bridgeReport) expired() bool {
	return b.status.Login == loginExpired
}

// collectBridges asks each running bridge for its status. A socket that
// refuses or is gone belongs to a bridge that is not running, so it is
// skipped. The collection makes no network call.
func collectBridges() ([]bridgeReport, error) {
	held, err := heldBridges()
	if err != nil {
		return nil, err
	}
	var reports []bridgeReport
	for _, h := range held {
		res, err := requestStatus(h.sock)
		switch {
		case connRefused(err) || errors.Is(err, fs.ErrNotExist):
			continue
		case err != nil:
			reports = append(reports, bridgeReport{sock: h.sock, problem: err.Error()})
		case slices.ContainsFunc(res.Problems, func(p string) bool { return strings.Contains(p, unknownStatusOp) }):
			reports = append(reports, bridgeReport{sock: h.sock, older: true})
		case len(res.Problems) > 0:
			reports = append(reports, bridgeReport{sock: h.sock, problem: strings.Join(res.Problems, "; ")})
		default:
			reports = append(reports, bridgeReport{sock: h.sock, status: res})
		}
	}
	slices.SortFunc(reports, func(a, b bridgeReport) int { return strings.Compare(a.heading(), b.heading()) })

	return reports, nil
}

// errLoginExpired is the failure of a command that finds a bridge with an
// expired login.
var errLoginExpired = errors.New("a bridge waits for a new login: run `loupe login`")

func newBridgeStatusCmd() *cobra.Command {
	var name string

	cmd := &cobra.Command{
		Use:   "status",
		Short: "Show the running bridges, their login, their workers and their last error",
		Long: "Asks each running bridge on this machine to describe itself, over its control " +
			"socket. It makes no network call. For each bridge it prints the rule file, the " +
			"process id, the CLI version, the log file, whether the login is valid, the time of " +
			"the last heartbeat the server accepted, the last warning or error of the log, and " +
			"each worker that runs.\n\n" +
			"A bridge on an older CLI cannot report its status. The command says so, and " +
			"`loupe update` fixes it.\n\n" +
			"The command exits with status 1 when no bridge runs, or when a bridge shown waits " +
			"for a new login. With --bridge it judges that bridge only.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			reports, err := collectBridges()
			if err != nil {
				return err
			}
			out := cmd.OutOrStdout()
			if name != "" {
				if reports, err = bridgesNamed(reports, name); err != nil {
					return err
				}
			}
			if len(reports) == 0 {
				fmt.Fprintln(out, "No bridge is running.")
				fmt.Fprintln(out, "Start one with `loupe bridge run -d`.")
				fmt.Fprintln(out, "See why the last bridge stopped with `loupe bridge logs`.")

				return errors.New("no bridge is running")
			}
			expired := false
			for i, b := range reports {
				if i > 0 {
					fmt.Fprintln(out)
				}
				printBridge(out, b)
				expired = expired || b.expired()
			}
			if expired {
				return errLoginExpired
			}

			return nil
		},
	}
	cmd.Flags().StringVar(&name, "bridge", "", "show the bridge with this `name`")

	return cmd
}

// bridgesNamed keeps the one running bridge that has the name.
func bridgesNamed(reports []bridgeReport, name string) ([]bridgeReport, error) {
	var found []bridgeReport
	for _, b := range reports {
		if b.status.Name == name {
			found = append(found, b)
		}
	}
	switch len(found) {
	case 0:
		return nil, fmt.Errorf("no running bridge is named %q: run `loupe bridge status` to list the bridges", name)
	case 1:
		return found, nil
	}

	return nil, fmt.Errorf("Two running bridges are named %q. Set `name:` in one rule file to tell them apart.", name)
}

func printBridge(out io.Writer, b bridgeReport) {
	fmt.Fprintln(out, b.heading())
	switch {
	case b.older:
		fmt.Fprintln(out, "  Runs on an older CLI that cannot report its status. Run `loupe update`.")

		return
	case b.problem != "":
		fmt.Fprintln(out, "  Runs, but gave no status: "+b.problem)

		return
	}
	s := b.status
	fmt.Fprintf(out, "  Rule file:   %s\n", s.RulesPath)
	fmt.Fprintf(out, "  PID:         %d\n", s.PID)
	fmt.Fprintf(out, "  CLI version: %s\n", s.Version)
	fmt.Fprintf(out, "  Log file:    %s\n", s.LogFile)
	if b.expired() {
		fmt.Fprintln(out, "  Login:       expired, waiting for `loupe login`")
	} else {
		fmt.Fprintln(out, "  Login:       valid")
	}
	if s.LastHeartbeatAt != nil {
		fmt.Fprintf(out, "  Heartbeat:   last accepted at %s\n", s.LastHeartbeatAt.Format(time.RFC3339))
	} else {
		fmt.Fprintln(out, "  Heartbeat:   none accepted yet")
	}
	if e := s.LastError; e != nil {
		text := e.Event
		if e.Message != "" {
			text += ": " + e.Message
		}
		fmt.Fprintf(out, "  Last error:  %s (%s)\n", text, e.Time.Format(time.RFC3339))
	} else {
		fmt.Fprintln(out, "  Last error:  none")
	}
	if len(s.Workers) == 0 {
		fmt.Fprintln(out, "  Workers:     none running")

		return
	}
	fmt.Fprintln(out, "  Workers:")
	for _, w := range s.Workers {
		card := "no card"
		if w.Card != 0 {
			card = fmt.Sprintf("card %d", w.Card)
		}
		fmt.Fprintf(out, "    %s, %s, started at %s\n", card, w.Kind, w.StartedAt.Format(time.RFC3339))
	}
}

// bridgesLine sums the bridges for `loupe status`. The second value says that
// a bridge waits for a new login.
func bridgesLine(reports []bridgeReport) (string, bool) {
	if len(reports) == 0 {
		return "none running.", false
	}
	n := len(reports)
	expired, unknown := false, false
	for _, b := range reports {
		expired = expired || b.expired()
		unknown = unknown || b.older || b.problem != ""
	}
	switch {
	case expired:
		return fmt.Sprintf("%d running, login expired. See `loupe bridge status`.", n), true
	case unknown:
		return fmt.Sprintf("%d running, login unknown. See `loupe bridge status`.", n), false
	}

	return fmt.Sprintf("%d running, login valid.", n), false
}
