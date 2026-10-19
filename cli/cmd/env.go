package cmd

import (
	"bufio"
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"net"
	"os"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

const (
	opRefresh = "refresh"
	opReset   = "reset"
)

// Exit codes of `loupe env`. Zero means the command ran, or the entry names
// none.
const (
	exitEnvFailed    = 1
	exitNotBridgeRun = 2
)

// envTimeout bounds the wait of `loupe env` for the bridge. The command of
// the entry never runs longer than rules.MaxBeforeTimeout, so this leaves the
// bridge room to answer first.
const envTimeout = rules.MaxBeforeTimeout + time.Minute

// envResult is the answer of the bridge to a refresh or a reset. Ran says
// whether the entry names a command. Output is the tail of its output, and
// Problem says why the request failed. NotWorker marks a session that no live
// worker of this bridge holds.
type envResult struct {
	OK        bool   `json:"ok"`
	Ran       bool   `json:"ran,omitempty"`
	Output    string `json:"output,omitempty"`
	Problem   string `json:"problem,omitempty"`
	NotWorker bool   `json:"notWorker,omitempty"`
}

// exitError is an error that sets the exit status of the process.
type exitError struct {
	code int
	err  error
}

func (e *exitError) Error() string { return e.err.Error() }
func (e *exitError) Unwrap() error { return e.err }
func (e *exitError) ExitCode() int { return e.code }

// ExitCode gives the exit status that err sets, and false for an error that
// sets none.
func ExitCode(err error) (int, bool) {
	var e *exitError
	if errors.As(err, &e) {
		return e.code, true
	}

	return 0, false
}

func newEnvCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "env",
		Short: "Ask the bridge to refresh or reset the folder of this worker",
		Long: "A worker that the bridge started asks the bridge to run the refresh or the reset " +
			"command of its work entry, in the folder the worker runs in. The command is part " +
			"of the rule file, so the worker names no script.\n\n" +
			"The commands read " + socketEnv + " and " + sessionEnv + ", which the bridge sets for each worker.\n\n" +
			"Exit status 0 means the command ran, or the entry names none. Status 1 means the " +
			"command failed, and the tail of its output follows. Status 2 means this process " +
			"is not a worker of a running bridge.",
	}
	cmd.AddCommand(newEnvOpCmd(opRefresh, "Run the refresh command of the work entry in the worker folder"),
		newEnvOpCmd(opReset, "Run the reset command of the work entry in the worker folder"))

	return cmd
}

func newEnvOpCmd(op, short string) *cobra.Command {
	return &cobra.Command{
		Use:   op,
		Short: short,
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			sock, session := os.Getenv(socketEnv), os.Getenv(sessionEnv)
			if sock == "" || session == "" {
				return &exitError{exitNotBridgeRun, fmt.Errorf("this process is not a worker of a running bridge: %s and %s are not both set", socketEnv, sessionEnv)}
			}
			res, err := requestEnv(sock, op, session)
			if connRefused(err) || errors.Is(err, fs.ErrNotExist) {
				return &exitError{exitNotBridgeRun, fmt.Errorf("no bridge listens on %s", sock)}
			}
			if err != nil {
				return &exitError{exitEnvFailed, err}
			}
			switch {
			case res.NotWorker:
				return &exitError{exitNotBridgeRun, errors.New(cmp.Or(res.Problem, "the bridge runs no worker for this session"))}
			case !res.OK:
				return &exitError{exitEnvFailed, errors.New(cmp.Or(res.Problem, "the "+op+" failed"))}
			case !res.Ran:
				fmt.Fprintln(cmd.OutOrStdout(), "no "+op+" command in the work entry")
			default:
				fmt.Fprintln(cmd.OutOrStdout(), op+" done")
				if res.Output != "" {
					fmt.Fprintln(cmd.OutOrStdout(), res.Output)
				}
			}

			return nil
		},
	}
}

// requestEnv sends one refresh or reset request for the session to the socket
// and reads the answer.
func requestEnv(sock, op, session string) (envResult, error) {
	var res envResult
	deadline := time.Now().Add(envTimeout)
	conn, err := (&net.Dialer{Deadline: deadline}).Dial("unix", sock)
	if err != nil {
		return res, err
	}
	defer conn.Close()
	conn.SetDeadline(deadline)

	req, err := json.Marshal(map[string]string{"op": op, "sessionId": session})
	if err != nil {
		return res, err
	}
	if _, err := conn.Write(append(req, '\n')); err != nil {
		return res, fmt.Errorf("send the %s: %w", op, err)
	}
	line, err := bufio.NewReader(conn).ReadString('\n')
	if err != nil {
		return res, fmt.Errorf("read the answer of the bridge: %w", err)
	}
	if err := json.Unmarshal([]byte(line), &res); err != nil {
		return res, fmt.Errorf("read the answer of the bridge: %w", err)
	}

	return res, nil
}

// runEnv runs the refresh or the reset command of the live worker of the
// session, in the folder that worker runs in. One command runs for a session
// at a time.
func (r *router) runEnv(ctx context.Context, op, session string) envResult {
	r.mu.Lock()
	var live *liveRun
	for _, run := range r.live {
		if run.p.spec.sessionID == session && !run.before && !run.p.isCommand() {
			live = &run

			break
		}
	}
	if live == nil {
		r.mu.Unlock()

		return envResult{NotWorker: true, Problem: "the bridge runs no worker for this session"}
	}
	if r.stops[live.p.runID] {
		r.mu.Unlock()

		return envResult{Problem: "a person stopped this worker"}
	}
	if r.envBusy[session] {
		r.mu.Unlock()

		return envResult{Problem: "another refresh or reset runs for this session"}
	}
	if r.envBusy == nil {
		r.envBusy = map[string]bool{}
	}
	r.envBusy[session] = true
	spec, ended := live.p.spec, live.ended
	r.mu.Unlock()
	defer func() {
		r.mu.Lock()
		delete(r.envBusy, session)
		r.mu.Unlock()
	}()

	if spec.dir == "" {
		return envResult{Problem: "the bridge holds no folder for this worker"}
	}
	command := spec.refresh
	if op == opReset {
		command = spec.reset
	}
	if command == nil {
		return envResult{OK: true}
	}

	run := r.worker.command
	if run == nil {
		run = runRuleCommand
	}
	ctx, cancel := context.WithTimeout(ctx, cmp.Or(command.Timeout, rules.DefaultBeforeTimeout))
	defer cancel()
	// The command does not outlive the worker.
	go func() {
		select {
		case <-ended:
			cancel()
		case <-ctx.Done():
		}
	}()
	res := run(ctx, procSpec{argv: command.Argv, dir: spec.dir, merge: true}, func(workerProc) {})
	if res.runDir != "" {
		defer os.RemoveAll(res.runDir)
	}
	if failure := res.failureOf(commandProc); failure != "" {
		return envResult{Ran: true, Problem: failedOutput(failure, res.output)}
	}
	r.log.Info("env_"+op, "session_id", session, "dir", spec.dir)

	return envResult{OK: true, Ran: true, Output: res.output}
}
