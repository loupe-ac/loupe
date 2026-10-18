//go:build unix

package cmd

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"syscall"
	"time"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

const (
	daemonFlag = "daemon"
	// daemonStartTimeout bounds the wait for the control socket of a new
	// daemon. The bridge checks the rule file against the server first.
	daemonStartTimeout = 60 * time.Second
	// stopTimeout bounds the wait for a stopped bridge to release its lock.
	// The bridge kills its workers and sends their reports first.
	stopTimeout = 60 * time.Second
	// maxStartError bounds the part of a failed start that the parent prints.
	maxStartError = 2048
)

// startDaemon runs this binary again as a detached `loupe bridge run` and
// returns once the child listens on its control socket. Go cannot fork, so the
// child starts from scratch in a new session. The parent reads the rule path to
// find that socket.
func startDaemon(cmd *cobra.Command, o bridgeRunOptions) error {
	path, err := rulesPathOr(o.rulesPath)
	if err != nil {
		return err
	}
	sock, err := socketPath(path)
	if err != nil {
		return err
	}
	if controlAnswers(sock) {
		fmt.Fprintf(cmd.OutOrStdout(), "the bridge already runs and reads %s\n", path)

		return nil
	}
	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("find the loupe binary: %w", err)
	}
	logPath := o.logFile
	if logPath == "" {
		logPath = defaultLogPath()
	}

	// The child writes a failure to this file, which the parent shows when the
	// child ends before it listens.
	failure, err := os.CreateTemp("", "loupe-bridge-start-*")
	if err != nil {
		return fmt.Errorf("create the start log: %w", err)
	}
	defer os.Remove(failure.Name())
	defer failure.Close()

	child := exec.Command(exe, daemonArgs(cmd.Flags())...)
	child.Stderr = failure
	child.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	if err := child.Start(); err != nil {
		return fmt.Errorf("start the bridge: %w", err)
	}
	exited := make(chan error, 1)
	go func() { exited <- child.Wait() }()

	deadline := time.After(daemonStartTimeout)
	for tick := time.NewTicker(100 * time.Millisecond); ; <-tick.C {
		select {
		case err := <-exited:
			return fmt.Errorf("the bridge stopped at start (%v)%s", err, startFailure(failure.Name()))
		case <-deadline:
			return fmt.Errorf("the bridge did not listen within %s; read %s", daemonStartTimeout, logPath)
		default:
		}
		if controlAnswers(sock) {
			fmt.Fprintf(cmd.OutOrStdout(), "the bridge runs in the background (pid %d)\nlog: %s\nstop it with: loupe bridge stop\n", child.Process.Pid, logPath)

			return nil
		}
	}
}

// daemonArgs rebuilds the command line of the child from the flags that were
// set, without the daemon flag.
func daemonArgs(flags *pflag.FlagSet) []string {
	args := []string{"bridge", "run"}
	flags.Visit(func(f *pflag.Flag) {
		if f.Name != daemonFlag {
			args = append(args, "--"+f.Name+"="+f.Value.String())
		}
	})

	return args
}

// startFailure reads what the child wrote to its start log, as an indented
// block that follows the error.
func startFailure(path string) string {
	f, err := os.Open(path)
	if err != nil {
		return ""
	}
	defer f.Close()
	text, _ := io.ReadAll(io.LimitReader(f, maxStartError))
	if msg := strings.TrimSpace(string(text)); msg != "" {
		return ":\n" + msg
	}

	return ""
}

// controlAnswers reports whether a bridge accepts connections on sock.
func controlAnswers(sock string) bool {
	conn, err := net.DialTimeout("unix", sock, time.Second)
	if err != nil {
		return false
	}
	conn.Close()

	return true
}

func newBridgeStopCmd() *cobra.Command {
	var rulesPath string

	cmd := &cobra.Command{
		Use:   "stop",
		Short: "Stop the running bridge",
		Long: "Tells the bridge that reads the rule file to stop. The bridge ends its " +
			"workers, sends their reports and exits. This command returns once the bridge " +
			"has exited, and fails when none runs.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			path, err := rulesPathOr(rulesPath)
			if err != nil {
				return err
			}
			abs, err := filepath.Abs(path)
			if err != nil {
				return err
			}
			sock, err := socketPath(abs)
			if err != nil {
				return err
			}
			// The rule path may resolve to another file than the one the bridge
			// locked, so read its locks from the files that name its socket.
			locks, err := locksNaming(sock)
			if err != nil {
				return err
			}
			res, err := requestControl(sock, "stop", requestTimeout)
			if connRefused(err) || errors.Is(err, fs.ErrNotExist) {
				return fmt.Errorf("no running bridge reads %s", abs)
			}
			if err != nil {
				return err
			}
			if !res.OK {
				return fmt.Errorf("the bridge did not stop: %s", strings.Join(res.Problems, "; "))
			}
			for _, lock := range locks {
				if err := waitUnlocked(lock, stopTimeout); err != nil {
					return err
				}
			}
			fmt.Fprintln(cmd.OutOrStdout(), "stopped the bridge that reads "+abs)

			return nil
		},
	}
	cmd.Flags().StringVar(&rulesPath, "rules", "", "stop the bridge that reads this `path`; empty uses rules.yaml in your config directory")

	return cmd
}

// locksNaming lists the held bridge locks whose file names sock.
func locksNaming(sock string) ([]string, error) {
	bridges, err := heldBridges()
	if err != nil {
		return nil, err
	}
	if i := slices.IndexFunc(bridges, func(b heldBridge) bool { return b.sock == sock }); i >= 0 {
		return bridges[i].locks, nil
	}

	return nil, nil
}

// waitUnlocked waits until no process holds the bridge lock at path. The
// bridge closes its socket as soon as it stops, and releases the lock only
// after its workers end, or when it dies.
func waitUnlocked(path string, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	for {
		held, err := lockHeldAt(path)
		if err != nil || !held {
			return err
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("the bridge did not exit within %s; it ends its workers first, so read the log", timeout)
		}
		time.Sleep(100 * time.Millisecond)
	}
}

// lockHeldAt reports whether another open file holds the lock at path. A
// missing file holds no lock.
func lockHeldAt(path string) (bool, error) {
	f, err := os.Open(path)
	if errors.Is(err, fs.ErrNotExist) {
		return false, nil
	}
	if err != nil {
		return false, fmt.Errorf("open the bridge lock: %w", err)
	}
	defer f.Close()
	if err := tryLockFile(f); err != nil {
		if lockHeld(err) {
			return true, nil
		}

		return false, fmt.Errorf("lock %s: %w", path, err)
	}

	return false, nil
}
