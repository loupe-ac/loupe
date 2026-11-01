package cmd

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/envfile"
	harn "github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// maxLaunchReason is the longest failure reason the server takes.
const maxLaunchReason = 1000

// staleScriptAge is the age past which the bridge deletes a launch script at
// start. A script that ran has deleted itself already.
const staleScriptAge = 24 * time.Hour

// launchWaitDelay bounds the wait for the output of a launcher that exited.
// Tests shorten it.
var launchWaitDelay = time.Second

// defaultScriptDir holds the launch scripts.
func defaultScriptDir() string {
	tmp, _ := config.TempDir()

	return filepath.Join(tmp, "loupe-sessions")
}

// withSetupEnv adds LOUPE_CONFIG to env when the setup is not the default, so
// a launch script exports it.
func withSetupEnv(env []string) []string {
	setup := config.Current()
	if setup.Source == config.SourceDefault {
		return env
	}
	env = withEnv(env, config.EnvVar, setup.Dir)
	if setupPin == "" {
		return env
	}
	path := os.Getenv("PATH")
	if i := slices.IndexFunc(env, func(e string) bool { return strings.HasPrefix(e, "PATH=") }); i >= 0 {
		path = strings.TrimPrefix(env[i], "PATH=")
	}

	return withEnv(env, "PATH", pinFirst(path))
}

// writeLaunchScript writes the script of one session into root and returns its
// path. O_EXCL refuses a file or a link that is already there.
func writeLaunchScript(root, sessionID, body string) (string, error) {
	if err := os.MkdirAll(root, 0o700); err != nil {
		return "", fmt.Errorf("create the launch script directory: %w", err)
	}
	if err := os.Chmod(root, 0o700); err != nil {
		return "", fmt.Errorf("protect the launch script directory: %w", err)
	}
	path := filepath.Join(root, sessionID+".sh")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o700)
	if err != nil {
		return "", fmt.Errorf("create the launch script: %w", err)
	}
	_, err = f.WriteString(body)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		os.Remove(path)

		return "", fmt.Errorf("write the launch script: %w", err)
	}

	return path, nil
}

// cleanLaunchScripts deletes each file in root older than staleScriptAge. Such
// a script belongs to a launch that never ran it.
func cleanLaunchScripts(root string, now time.Time, log *slog.Logger) {
	entries, err := os.ReadDir(root)
	if errors.Is(err, fs.ErrNotExist) {
		return
	}
	if err != nil {
		log.Warn("launch_scripts_unread", "dir", root, "error", err.Error())

		return
	}
	for _, entry := range entries {
		info, err := entry.Info()
		if err != nil || !info.Mode().IsRegular() || now.Sub(info.ModTime()) < staleScriptAge {
			continue
		}
		if err := os.Remove(filepath.Join(root, entry.Name())); err != nil && !errors.Is(err, fs.ErrNotExist) {
			log.Warn("launch_script_not_removed", "file", entry.Name(), "error", err.Error())
		}
	}
}

// launchAborted is the failure of a launch whose work request ended first.
const launchAborted = "the work request ended before the session opened"

// errLaunchAborted stops a launch command from starting.
var errLaunchAborted = errors.New(launchAborted)

// runLauncher runs the launch command and returns why it failed, or "" when it
// launched. A command still running at the timeout counts as launched. The
// bridge never kills it then, because it may be the terminal the session runs
// in. A ctx that ends before kills the command, and no session opens.
//
// begin starts the command, so a caller can start it under a lock. A nil one
// starts it at once.
func runLauncher(ctx context.Context, argv []string, timeout time.Duration, begin func(start func() error) error) string {
	cmd := exec.Command(argv[0], argv[1:]...)
	out := &capWriter{limit: 4 * maxLaunchReason}
	cmd.Stdout, cmd.Stderr = out, out
	// A launcher can leave a child that holds its output open after it exits.
	cmd.WaitDelay = launchWaitDelay
	newProcessGroup(cmd)
	if begin == nil {
		begin = func(start func() error) error { return start() }
	}
	err := begin(cmd.Start)
	if errors.Is(err, errLaunchAborted) {
		return launchAborted
	}
	if err != nil {
		return err.Error()
	}
	// The channel has room, so the reaper never blocks after the timeout.
	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()
	timer := time.NewTimer(timeout)
	defer timer.Stop()

	select {
	case err = <-done:
	case <-timer.C:
		return ""
	case <-ctx.Done():
		_ = signalGroup(cmd.Process.Pid, stopKill)
		<-done

		return launchAborted
	}
	var exitErr *exec.ExitError
	switch {
	case err == nil, errors.Is(err, exec.ErrWaitDelay):
		return ""
	case errors.As(err, &exitErr) && exitErr.ExitCode() >= 0:
		reason := "exit code " + strconv.Itoa(exitErr.ExitCode())
		if text := strings.TrimSpace(strings.ToValidUTF8(out.buf.String(), "")); text != "" {
			reason += ": " + text
		}

		return head(reason, maxLaunchReason)
	}

	return err.Error()
}

// head is the first n characters of s.
func head(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}

	return string(r[:n])
}

// isItemSession reports whether p opens the session of an inbox item that has
// no single card, which works in a worktree of its own.
func isItemSession(p pending) bool {
	return p.isWork() && p.action == rules.ActionInteractive && p.work.Kind == rules.SessionKind && p.work.SubjectType == api.SubjectInboxItem
}

// endItemLaunch ends the launch of the item worktree at dir: it hands the
// worktree to the sweep when the launch worked, and removes it when not. An
// empty dir is no item launch.
func (r *router) endItemLaunch(dir string, launched bool) {
	switch {
	case dir == "":
	case launched:
		r.itemSessions().finish(dir)
	default:
		r.itemSessions().discard(context.Background(), dir)
	}
}

// launch is one interactive session to open, with what its run needs.
type launch struct {
	p       pending
	command rules.Launch
	dir     string
	// ctx ends when the work request of the launch is no longer the bridge's.
	ctx context.Context
}

// launchLocked opens an interactive session for p on its own goroutine. It
// takes no worker slot and no card key, so a worker of the card runs beside
// it. A shut router opens nothing. ctx aborts the launch until its command
// returns. The caller holds mu.
func (r *router) launchLocked(p pending, ctx context.Context) {
	if r.shut() {
		return
	}
	p.spec.sessionID = r.worker.sessionID()
	p.spec.runID = p.runID
	l := launch{p: p, command: p.set.Launch(), dir: r.scriptDir, ctx: ctx}
	if l.dir == "" {
		l.dir = defaultScriptDir()
	}
	r.launching++
	r.log.Info("session_launching", append(about(p.event, p.rule), "session_id", p.spec.sessionID)...)
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()
		r.runLaunch(l)
	}()
}

// beginLaunch starts the launch command of a work claim under mu, after a last
// check that the claim holds. endClaimLocked cancels ctx under mu too, so a
// claim that ends before the start keeps the command from starting. A rule
// launch starts at once.
func (r *router) beginLaunch(l launch) func(start func() error) error {
	if !l.p.isWork() {
		return nil
	}

	return func(start func() error) error {
		r.mu.Lock()
		defer r.mu.Unlock()
		if l.ctx.Err() != nil {
			return errLaunchAborted
		}

		return start()
	}
}

// runLaunch writes the script, runs the launch command and reports how it went.
// The count drops under mu with the enqueue, so a drain never misses the report.
func (r *router) runLaunch(l launch) {
	p := l.p
	reason := ""
	var err error
	itemDir := ""
	if isItemSession(p) {
		itemDir, err = r.itemSessions().prepare(context.Background(), p.spec.dir, p.project, p.work.Context.InboxItemNumber, p.spec.sessionID)
		if err == nil {
			p.spec.dir = itemDir
		}
	}
	var env []string
	if err == nil {
		env, err = p.spec.accountEnv()
	}
	path := ""
	adapter := p.spec.adapter()
	program := ""
	if err == nil {
		// The account PATH can differ from the bridge PATH. The script changes
		// directory before it runs the program, so the path must be absolute.
		if program, err = envfile.LookPath(adapter.Program(), env, p.spec.dir); err == nil {
			program, err = filepath.Abs(program)
		}
	}
	if err == nil {
		path, err = writeLaunchScript(l.dir, p.spec.sessionID, adapter.Interactive(program, p.spec.harnessSpec(withSetupEnv(env))))
	}
	if rec, ok := adapter.(harn.LaunchRecorder); ok && err == nil {
		// A failed record only costs the usage of the session, so the launch goes on.
		if rerr := rec.RecordLaunch(p.spec.sessionID, p.spec.dir, time.Now()); rerr != nil {
			r.log.Warn("launch_not_recorded", append(about(p.event, p.rule), "session_id", p.spec.sessionID, "error", rerr.Error())...)
		}
	}
	switch {
	case l.ctx.Err() != nil:
		reason = launchAborted
		if err == nil {
			os.Remove(path)
		}
		r.endItemLaunch(itemDir, false)
		if rec, ok := adapter.(harn.LaunchRecorder); ok {
			rec.ForgetLaunch(p.spec.sessionID)
		}
	case err != nil:
		reason = err.Error()
		r.endItemLaunch(itemDir, false)
	default:
		if r.beforeLaunch != nil {
			r.beforeLaunch()
		}
		_, number := cardOf(p.event)
		cardNumber := ""
		if number > 0 {
			cardNumber = strconv.Itoa(number)
		}
		reason = runLauncher(l.ctx, l.command.Argv(rules.LaunchValues{
			Script: path, Dir: p.spec.dir, SessionID: p.spec.sessionID, CardNumber: cardNumber, Project: p.project,
		}), l.command.Timeout, r.beginLaunch(l))
		r.endItemLaunch(itemDir, reason == "")
		if reason != "" {
			os.Remove(path)
			if rec, ok := adapter.(harn.LaunchRecorder); ok {
				rec.ForgetLaunch(p.spec.sessionID)
			}
		}
	}

	report := api.InteractiveLaunchReport{State: api.RunRunning, Harness: p.spec.harnessName, Account: p.spec.account, Model: p.spec.model}
	if reason != "" {
		report.State, report.FailureReason = api.RunNotStarted, reason
		r.log.Error("session_launch_failed", append(about(p.event, p.rule), "session_id", p.spec.sessionID, "reason", reason)...)
	} else {
		r.log.Info("session_launched", append(about(p.event, p.rule), "session_id", p.spec.sessionID)...)
	}

	r.mu.Lock()
	r.launching--
	cardID, number := cardOf(p.event)
	if r.reporting() && (number >= 1 || isItemSession(p)) {
		report.BridgeID, report.WorkKind, report.At = r.bridgeID, p.rule, time.Now()
		report.SubjectType, report.SubjectID, report.CardNumber = api.SubjectCard, cardID, number
		if isItemSession(p) {
			report.SubjectType, report.SubjectID, report.CardNumber = api.SubjectInboxItem, p.work.SubjectID, 0
		}
		if p.isWork() {
			report.WorkRequestID, report.WorkKind, report.RuleID = p.work.WorkRequestID, p.work.Kind, p.work.RuleID
		}
		r.reports.Enqueue(r.runs.launch(p.event.ProjectID, p.spec.sessionID, report))
	}
	// A launch of a work request is its whole run, so its result follows.
	launched := workerResult{command: true}
	if reason != "" {
		launched.exitCode = -1
	}
	st, post := r.workResultLocked(p, launched, false)
	r.mu.Unlock()
	if post {
		r.settleWork(p, st)
	}
}
