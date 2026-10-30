package cmd

import (
	"bufio"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"maps"
	"net"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/spf13/cobra"
)

const (
	// maxSocketPath is the sun_path size on macOS, the smallest of the hosts.
	maxSocketPath = 104
	// maxRequest bounds one request line, without its newline.
	maxRequest = 1024
	// requestTimeout bounds the read of a request and the write of its answer.
	requestTimeout = 5 * time.Second
	// reloadTimeout bounds a whole `loupe bridge reload`. The bridge checks the
	// rule file against the server, which can take several requests.
	reloadTimeout = 60 * time.Second
	// reloadBuildTimeout and reloadAccountsTimeout, the bound of the account
	// checks, add up to less than reloadTimeout, so the bridge answers first.
	reloadBuildTimeout    = 30 * time.Second
	reloadAccountsTimeout = 20 * time.Second
)

// rulesPathOr gives path, or the default rule file when path is empty.
func rulesPathOr(path string) (string, error) {
	if path != "" {
		return path, nil
	}

	return defaultRulesPath()
}

// socketPath names the control socket of the bridge that reads rulesPath. The
// name comes from the absolute path as given. A repointed symlink thus keeps
// the address that the running bridge listens on.
func socketPath(rulesPath string) (string, error) {
	abs, err := filepath.Abs(rulesPath)
	if err != nil {
		return "", err
	}

	return bridgeFile("bridge-", abs, ".sock")
}

// lockPath names the lock file of the bridge that reads rulesPath. The name
// comes from the absolute path with symlinks resolved, so two paths to one
// file share the lock. A path that does not resolve stays as is.
func lockPath(rulesPath string) (string, error) {
	key, err := lockKey(rulesPath)
	if err != nil {
		return "", err
	}

	return bridgeFile("bridge-", key, ".lock")
}

// lockKey is the absolute rule path with symlinks resolved, when they resolve.
func lockKey(rulesPath string) (string, error) {
	abs, err := filepath.Abs(rulesPath)
	if err != nil {
		return "", err
	}
	if real, err := filepath.EvalSymlinks(abs); err == nil {
		abs = real
	}

	return abs, nil
}

// bridgeFile names a file in the config directory from a prefix and a hash of
// key.
func bridgeFile(prefix, key, ext string) (string, error) {
	dir, err := config.Dir()
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256([]byte(key))

	return filepath.Join(dir, prefix+hex.EncodeToString(sum[:])[:12]+ext), nil
}

// bridgeLock is the lock that a running bridge holds on the file that its
// rule path resolves to. A reload moves it, and runs before the bridge closes it.
type bridgeLock struct {
	rulesPath, sock, path string
	f                     *os.File
	// resolve names the lock file of a rule path; tests replace it.
	resolve func(rulesPath string) (string, error)
}

// lockBridge takes the lock of the bridge that reads rulesPath and writes sock
// into the lock file. The OS releases the lock when the file closes or the
// process dies.
func lockBridge(rulesPath, sock string) (*bridgeLock, error) {
	path, err := lockPath(rulesPath)
	if err != nil {
		return nil, err
	}
	var f *os.File
	if err := withMoveGuard(true, func() (err error) {
		f, err = lockFileAt(path, sock)

		return err
	}); err != nil {
		return nil, err
	}

	return &bridgeLock{rulesPath: rulesPath, sock: sock, path: path, f: f, resolve: lockPath}, nil
}

// follow takes the lock of the file that the rule path resolves to now, when
// that file changed. After the load, check fails when the path resolves to
// another file again. The caller then calls done with whether it applied the
// reload, and done releases the lock that the bridge no longer needs.
func (l *bridgeLock) follow() (check func() error, done func(applied bool), err error) {
	path, err := l.resolve(l.rulesPath)
	if err != nil {
		return nil, nil, err
	}
	check = func() error {
		if now, err := l.resolve(l.rulesPath); err != nil || now != path {
			return fmt.Errorf("the rule file %s moved during the reload: run the reload again", l.rulesPath)
		}

		return nil
	}
	if path == l.path {
		return check, func(bool) {}, nil
	}
	var f *os.File
	if err := withMoveGuard(true, func() (err error) {
		f, err = lockFileAt(path, l.sock)

		return err
	}); err != nil {
		return nil, nil, err
	}

	return check, func(applied bool) {
		released := false
		release := func() error {
			released = true
			if !applied {
				f.Close()

				return nil
			}
			l.f.Close()
			l.f, l.path = f, path

			return nil
		}
		if withMoveGuard(true, release) != nil && !released {
			release()
		}
	}, nil
}

// withMoveGuard runs fn while it holds the lock of the guard file, alone when
// exclusive. A bridge holds it alone while it takes or moves its lock, and a
// scan of the lock files shares it. A scan thus never sees a move half done,
// and its probe of a lock file never makes a bridge fail to take that lock.
func withMoveGuard(exclusive bool, fn func() error) error {
	dir, err := config.Dir()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}
	path := filepath.Join(dir, "bridge.guard")
	g, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return fmt.Errorf("open the bridge guard: %w", err)
	}
	defer g.Close()
	if err := waitLockFile(g, exclusive); err != nil {
		return fmt.Errorf("lock %s: %w", path, err)
	}

	return fn()
}

func (l *bridgeLock) Close() error {
	return l.f.Close()
}

// lockFileAt opens the lock file at path, takes its lock and writes sock into it.
func lockFileAt(path, sock string) (*os.File, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, fmt.Errorf("create config dir: %w", err)
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return nil, fmt.Errorf("open the bridge lock: %w", err)
	}
	if err := tryLockFile(f); err != nil {
		f.Close()
		if !lockHeld(err) {
			return nil, fmt.Errorf("lock %s: %w", path, err)
		}
		where := ""
		if other, err := os.ReadFile(path); err == nil && len(other) > 0 {
			where = " and listens on " + string(other)
		}

		return nil, fmt.Errorf("another bridge reads the same rule file and holds %s%s: stop it first, or run `loupe bridge reload` to apply a change", path, where)
	}
	if err := f.Truncate(0); err == nil {
		f.WriteAt([]byte(sock), 0)
	}

	return f, nil
}

// listenControl listens on path. The caller holds the bridge lock. A bridge
// that still answers there got the same path to a symlink that has since been
// repointed, so the second one refuses to start. A file that nothing answers
// on is left by a bridge that crashed, and listenControl removes it. The
// config directory is 0700, so only its owner can connect.
func listenControl(path string) (net.Listener, error) {
	if len(path) >= maxSocketPath {
		if setup := config.Current(); setup.Source != config.SourceDefault {
			return nil, fmt.Errorf("the control socket path %s has %d bytes, and a socket path must be shorter than %d bytes: use a shorter setup folder than %s", path, len(path), maxSocketPath, setup.Dir)
		}

		return nil, fmt.Errorf("the control socket path %s has %d bytes, and a socket path must be shorter than %d bytes", path, len(path), maxSocketPath)
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, fmt.Errorf("create config dir: %w", err)
	}

	conn, err := net.DialTimeout("unix", path, time.Second)
	if err == nil {
		conn.Close()

		return nil, fmt.Errorf("another bridge got the same rule path and listens on %s: stop it first, or run `loupe bridge reload` to apply a change", path)
	}
	if !connRefused(err) && !errors.Is(err, fs.ErrNotExist) {
		return nil, fmt.Errorf("probe the control socket %s: %w", path, err)
	}
	if err := os.Remove(path); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return nil, fmt.Errorf("remove the stale control socket: %w", err)
	}

	ln, err := net.Listen("unix", path)
	if err != nil {
		return nil, fmt.Errorf("listen on the control socket: %w", err)
	}
	if err := os.Chmod(path, 0o600); err != nil {
		ln.Close()

		return nil, fmt.Errorf("restrict the control socket: %w", err)
	}

	return ln, nil
}

// controlOps answers the ops of the control socket. update may send one early
// line before an exec ends the connection. When the exec fails, its return
// value follows as a second line.
type controlOps struct {
	// stop ends the bridge. The connection gets its answer first.
	stop   func()
	reload func(ctx context.Context) reloadResult
	update func(ctx context.Context, reply func(updateResult)) updateResult
	// credential answers a worker's request for the GitHub credentials of its
	// run.
	credential func(ctx context.Context, runID string) credentialResult
	// env runs the refresh or the reset command of the worker of a session.
	env func(ctx context.Context, op, session string) envResult
	// status describes the bridge. It reads state only and sends no request.
	status func() statusResult
}

// statusResult is the answer to the status op. It carries no token.
type statusResult struct {
	Name            string         `json:"name,omitempty"`
	RulesPath       string         `json:"rulesPath,omitempty"`
	PID             int            `json:"pid,omitempty"`
	Version         string         `json:"version,omitempty"`
	LogFile         string         `json:"logFile,omitempty"`
	Login           string         `json:"login,omitempty"`
	LastHeartbeatAt *time.Time     `json:"lastHeartbeatAt,omitempty"`
	LastError       *statusError   `json:"lastError,omitempty"`
	Workers         []statusWorker `json:"workers"`
	// Problems holds the refusal of a bridge that cannot answer the op.
	Problems []string `json:"problems,omitempty"`
}

// statusError is the last record of the log at the level Warn or above.
type statusError struct {
	Event   string    `json:"event"`
	Message string    `json:"message,omitempty"`
	Time    time.Time `json:"time"`
}

// statusWorker is a worker that runs now.
type statusWorker struct {
	Card      int       `json:"card,omitempty"`
	Kind      string    `json:"kind"`
	StartedAt time.Time `json:"startedAt"`
}

const (
	loginValid   = "valid"
	loginExpired = "expired"
)

// serveControl answers each connection on ln with ops until ctx ends. The
// channel closes when the listener and every connection are closed. Closing a
// Unix listener removes its socket file.
func serveControl(ctx context.Context, ln net.Listener, ops controlOps) <-chan struct{} {
	done := make(chan struct{})
	stop := context.AfterFunc(ctx, func() { ln.Close() })
	var wg sync.WaitGroup
	go func() {
		defer close(done)
		defer wg.Wait()
		defer stop()
		defer ln.Close()
		for {
			conn, err := ln.Accept()
			if errors.Is(err, net.ErrClosed) {
				return
			}
			if err != nil {
				time.Sleep(100 * time.Millisecond)

				continue
			}
			wg.Add(1)
			go func() {
				defer wg.Done()
				answer(ctx, conn, ops)
			}()
		}
	}()

	return done
}

// answer reads one JSON line and writes one JSON line back, or an early line
// and the answer for an update. A connection that sends no complete line gets
// no answer, and a connection gets one answer only.
func answer(ctx context.Context, conn net.Conn, ops controlOps) {
	defer conn.Close()
	stop := context.AfterFunc(ctx, func() { conn.Close() })
	defer stop()

	conn.SetReadDeadline(time.Now().Add(requestTimeout))
	line, err := bufio.NewReaderSize(io.LimitReader(conn, maxRequest+1), maxRequest+1).ReadString('\n')
	if err != nil && len(line) <= maxRequest {
		return
	}

	var mu sync.Mutex
	early, done := false, false
	write := func(res any, last bool) {
		mu.Lock()
		defer mu.Unlock()
		if done || (!last && early) {
			return
		}
		early = true
		if b, err := json.Marshal(res); err == nil {
			conn.SetWriteDeadline(time.Now().Add(requestTimeout))
			conn.Write(append(b, '\n'))
		}
		if last {
			done = true
			conn.Close()
		}
	}
	send := func(res any) { write(res, true) }
	var req struct {
		Op        string `json:"op"`
		RunID     string `json:"runId"`
		SessionID string `json:"sessionId"`
	}
	switch {
	case err != nil:
		send(refused(fmt.Sprintf("the request is longer than %d bytes", maxRequest)))
	case json.Unmarshal([]byte(line), &req) != nil:
		send(refused("the request is not one JSON object"))
	case req.Op == "reload" && ops.reload != nil:
		// A reload or an update runs longer than the deadline.
		conn.SetDeadline(time.Time{})
		send(ops.reload(ctx))
	case req.Op == "update" && ops.update != nil:
		conn.SetDeadline(time.Time{})
		send(ops.update(ctx, func(res updateResult) { write(res, false) }))
	case req.Op == "stop" && ops.stop != nil:
		send(reloadResult{OK: true})
		ops.stop()
	case req.Op == "credential" && ops.credential != nil:
		send(ops.credential(ctx, req.RunID))
	case req.Op == "status" && ops.status != nil:
		send(ops.status())
	case (req.Op == opRefresh || req.Op == opReset) && ops.env != nil:
		// The command of an entry runs longer than the deadline.
		conn.SetDeadline(time.Time{})
		if req.SessionID == "" {
			send(envResult{Problem: "the request names no session"})

			break
		}
		send(ops.env(ctx, req.Op, req.SessionID))
	default:
		send(refused(fmt.Sprintf("unknown op %q: only reload, update, stop, status, credential, refresh and reset are known", req.Op)))
	}
}

func refused(problem string) reloadResult {
	return reloadResult{Problems: []string{problem}}
}

func newBridgeReloadCmd() *cobra.Command {
	var rulesPath string

	cmd := &cobra.Command{
		Use:   "reload",
		Short: "Apply a changed rule file to the running bridge",
		Long: "Tells the bridge that reads the rule file to read it again. The bridge " +
			"parses the file, checks it against the server, and applies it only when " +
			"every check passes. It then also applies the server flags of the GET " +
			"/api/events answer it checked against. When a check fails, it keeps its " +
			"rules and flags, and this command prints each problem and exits with status 1.",
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
			res, err := requestReload(sock)
			if connRefused(err) || errors.Is(err, fs.ErrNotExist) {
				return fmt.Errorf("no running bridge reads %s", abs)
			}
			if err != nil {
				return err
			}

			if !res.OK {
				for _, problem := range res.Problems {
					if res.Stage != "" {
						problem = res.Stage + ": " + problem
					}
					fmt.Fprintln(cmd.ErrOrStderr(), problem)
				}

				return errors.New("the bridge did not apply the rule file")
			}
			printReload(cmd.OutOrStdout(), abs, res)

			return nil
		},
	}
	cmd.Flags().StringVar(&rulesPath, "rules", "", "reload the bridge that reads this `path`; empty uses rules.yaml in your config directory")

	return cmd
}

// requestReload sends one reload request to the socket and reads the answer.
func requestReload(sock string) (reloadResult, error) {
	return requestControl(sock, "reload", reloadTimeout)
}

// requestControl sends one op to the socket and reads the one line that comes
// back.
func requestControl(sock, op string, timeout time.Duration) (reloadResult, error) {
	var res reloadResult
	deadline := time.Now().Add(timeout)
	conn, err := (&net.Dialer{Deadline: deadline}).Dial("unix", sock)
	if err != nil {
		return res, err
	}
	defer conn.Close()
	conn.SetDeadline(deadline)

	if _, err := conn.Write([]byte(`{"op":"` + op + `"}` + "\n")); err != nil {
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

// requestStatus asks the bridge on sock to describe itself.
func requestStatus(sock string) (statusResult, error) {
	var res statusResult
	deadline := time.Now().Add(requestTimeout)
	conn, err := (&net.Dialer{Deadline: deadline}).Dial("unix", sock)
	if err != nil {
		return res, err
	}
	defer conn.Close()
	conn.SetDeadline(deadline)

	if _, err := conn.Write([]byte(`{"op":"status"}` + "\n")); err != nil {
		return res, fmt.Errorf("send the status request: %w", err)
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

// requestCredential asks the bridge for the GitHub credentials of a run. It
// retries a dial that finds no listener for window, because a bridge that hands
// over to a new image leaves the socket for a moment.
func requestCredential(sock, runID string, window, interval time.Duration) (credentialResult, error) {
	var res credentialResult
	deadline := time.Now().Add(window)
	for {
		conn, err := net.DialTimeout("unix", sock, requestTimeout)
		if err == nil {
			defer conn.Close()

			return exchangeCredential(conn, runID)
		}
		if !(connRefused(err) || errors.Is(err, fs.ErrNotExist)) || !time.Now().Add(interval).Before(deadline) {
			return res, err
		}
		time.Sleep(interval)
	}
}

// exchangeCredential sends the credential request on conn and reads the answer.
func exchangeCredential(conn net.Conn, runID string) (credentialResult, error) {
	var res credentialResult
	conn.SetDeadline(time.Now().Add(gitTokenTimeout + requestTimeout))
	req, err := json.Marshal(map[string]string{"op": "credential", "runId": runID})
	if err != nil {
		return res, err
	}
	if _, err := conn.Write(append(req, '\n')); err != nil {
		return res, fmt.Errorf("send the credential request: %w", err)
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

// printReload names the rule file, the rules and dirs the reload changed and
// the projects the bridge now maps.
func printReload(w io.Writer, path string, res reloadResult) {
	fmt.Fprintln(w, "reloaded "+path)
	for _, part := range []struct {
		label string
		names []string
	}{{"added", res.Added}, {"removed", res.Removed}, {"changed", res.Changed}, {"dir changed", res.Dirs}} {
		if len(part.names) > 0 {
			fmt.Fprintln(w, part.label+": "+strings.Join(part.names, ", "))
		}
	}
	if len(res.Added)+len(res.Removed)+len(res.Changed)+len(res.Dirs) == 0 {
		fmt.Fprintln(w, "no rule changed")
	}
	fmt.Fprintln(w, "projects: "+strings.Join(res.Projects, ", "))
	for _, name := range slices.Sorted(maps.Keys(res.AccountsOff)) {
		label := name
		if slices.Contains(res.AccountsUnused, name) {
			label += " (unused)"
		}
		fmt.Fprintln(w, "account "+label+" failing: "+res.AccountsOff[name])
	}
}
