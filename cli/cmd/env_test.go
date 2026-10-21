package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// envRules gives the implement entry a refresh and a reset command, and the
// plain entry none.
const envRules = `
accounts:
  claude:
    harness: claude-code
defaults:
  account: claude
projects:
  loupe:
    dir: {dir}
work:
  implement:
    prompt: Implement card {cardNumber}.
    refresh:
      run: [up, 'card-{cardNumber}']
      timeout: 2m
    reset:
      run: [down, 'card-{cardNumber}']
  plain:
    prompt: Plain card {cardNumber}.
`

// envFixture holds a live worker of a work request, and the fake command the
// bridge runs for it.
type envFixture struct {
	h       *harness
	session string
	folder  string
	release func()
	cmds    *fakeBefore
}

// liveEnv starts a worker of the kind and keeps it live until release.
func liveEnv(t *testing.T, kind string) *envFixture {
	t.Helper()
	h := newHarnessWith(t, envRules, rules.Defaults{})
	f := h.withWork()
	block := h.blocked()
	cmds := &fakeBefore{result: procResult{output: "ok"}}
	h.router.worker.command = cmds.run
	h.router.reports = syncQueue{}
	go h.offer(f, workRequest(1, 87, kind, api.WorkRequestOpen))
	spec := <-h.worker.started
	var once sync.Once
	release := func() { once.Do(func() { close(block); h.router.wg.Wait() }) }
	t.Cleanup(release)

	return &envFixture{h: h, session: spec.sessionID, folder: spec.dir, release: release, cmds: cmds}
}

func TestARefreshRunsTheCommandOfTheEntryInTheWorkerFolder(t *testing.T) {
	e := liveEnv(t, "implement")

	res := e.h.router.runEnv(context.Background(), opRefresh, e.session)

	if !res.OK || !res.Ran || res.Output != "ok" {
		t.Fatalf("res = %+v", res)
	}
	specs := e.cmds.recorded()
	if len(specs) != 1 || !slices.Equal(specs[0].argv, []string{"up", "card-87"}) || specs[0].dir != e.folder {
		t.Fatalf("command = %+v, want it in %s", specs, e.folder)
	}

	res = e.h.router.runEnv(context.Background(), opReset, e.session)
	specs = e.cmds.recorded()
	if !res.OK || !res.Ran || len(specs) != 2 || !slices.Equal(specs[1].argv, []string{"down", "card-87"}) {
		t.Fatalf("res = %+v, commands = %+v", res, specs)
	}
}

func TestAnEntryWithNoCommandRunsNothingAndSucceeds(t *testing.T) {
	e := liveEnv(t, "plain")

	res := e.h.router.runEnv(context.Background(), opRefresh, e.session)

	if !res.OK || res.Ran || len(e.cmds.recorded()) != 0 {
		t.Fatalf("res = %+v, commands = %+v", res, e.cmds.recorded())
	}
}

func TestAFailedRefreshReturnsTheReasonAndTheOutput(t *testing.T) {
	e := liveEnv(t, "implement")
	e.cmds.result = procResult{exitCode: 3, output: "no space left"}

	res := e.h.router.runEnv(context.Background(), opRefresh, e.session)

	if res.OK || !res.Ran || !strings.Contains(res.Problem, "exited with code 3") || !strings.Contains(res.Problem, "no space left") {
		t.Fatalf("res = %+v", res)
	}
}

func TestASessionWithNoLiveWorkerIsNoWorker(t *testing.T) {
	e := liveEnv(t, "implement")

	res := e.h.router.runEnv(context.Background(), opRefresh, "0199a0e2-0000-4000-8000-0000000000ff")

	if res.OK || !res.NotWorker || len(e.cmds.recorded()) != 0 {
		t.Fatalf("res = %+v", res)
	}
	e.release()
	if res := e.h.router.runEnv(context.Background(), opRefresh, e.session); !res.NotWorker {
		t.Fatalf("res after the worker ended = %+v", res)
	}
}

func TestOnlyOneEnvCommandRunsForASession(t *testing.T) {
	e := liveEnv(t, "implement")
	e.cmds.started, e.cmds.block = make(chan procSpec, 1), make(chan struct{})
	done := make(chan envResult, 1)
	go func() { done <- e.h.router.runEnv(context.Background(), opRefresh, e.session) }()
	<-e.cmds.started

	res := e.h.router.runEnv(context.Background(), opReset, e.session)
	close(e.cmds.block)

	if res.OK || res.NotWorker || !strings.Contains(res.Problem, "another") {
		t.Fatalf("second = %+v", res)
	}
	if first := <-done; !first.OK {
		t.Fatalf("first = %+v", first)
	}
}

func TestARefreshIsRefusedWhileAStopIsPending(t *testing.T) {
	e := liveEnv(t, "implement")
	e.h.router.mu.Lock()
	e.h.router.stops = map[string]bool{}
	for id := range e.h.router.live {
		e.h.router.stops[id] = true
	}
	e.h.router.mu.Unlock()

	res := e.h.router.runEnv(context.Background(), opRefresh, e.session)

	if res.OK || res.NotWorker || len(e.cmds.recorded()) != 0 {
		t.Fatalf("res = %+v, commands = %+v", res, e.cmds.recorded())
	}
}

func TestARefreshFailsWhenTheWorkerFolderIsUnknown(t *testing.T) {
	e := liveEnv(t, "implement")
	e.h.router.mu.Lock()
	for id, run := range e.h.router.live {
		run.p.spec.dir = ""
		e.h.router.live[id] = run
	}
	e.h.router.mu.Unlock()

	res := e.h.router.runEnv(context.Background(), opRefresh, e.session)

	if res.OK || res.NotWorker || len(e.cmds.recorded()) != 0 {
		t.Fatalf("res = %+v", res)
	}
}

func TestARefreshEndsWhenTheWorkerEnds(t *testing.T) {
	e := liveEnv(t, "implement")
	e.cmds.started, e.cmds.block = make(chan procSpec, 1), make(chan struct{})
	done := make(chan envResult, 1)
	go func() { done <- e.h.router.runEnv(context.Background(), opRefresh, e.session) }()
	<-e.cmds.started

	e.release()

	select {
	case res := <-done:
		if res.OK {
			t.Fatalf("res = %+v, want a failure", res)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("the command outlived the worker")
	}
}

// A handover keeps the commands, the folder and the socket of a worker, so an
// adopted worker can still ask for a refresh.
func TestAnAdoptedWorkerKeepsItsEnvCommands(t *testing.T) {
	e := liveEnv(t, "implement")
	e.h.router.mu.Lock()
	for id, run := range e.h.router.live {
		run.p.spec.socket = "/tmp/bridge.sock"
		e.h.router.live[id] = run
	}
	e.h.router.mu.Unlock()
	st := roundTrip(t, e.h.router.freeze())
	if len(st.Live) != 1 || st.Live[0].WorkerDir != e.folder || st.Live[0].Socket != "/tmp/bridge.sock" || st.Live[0].Refresh == nil || st.Live[0].Reset == nil {
		t.Fatalf("live = %+v", st.Live)
	}
	e.h.router.resume()
	e.release()

	next := newHarnessWith(t, envRules, rules.Defaults{})
	cmds := &fakeBefore{result: procResult{output: "ok"}}
	next.router.worker.command = cmds.run
	hold := make(chan struct{})
	next.router.worker.adopt = func(ctx context.Context, _ string) workerResult {
		select {
		case <-hold:
		case <-ctx.Done():
		}

		return workerResult{}
	}
	next.router.adopt(st)
	t.Cleanup(func() { close(hold); next.router.wg.Wait() })

	res := next.router.runEnv(context.Background(), opRefresh, e.session)

	specs := cmds.recorded()
	if !res.OK || !res.Ran || len(specs) != 1 || specs[0].dir != e.folder || !slices.Equal(specs[0].argv, []string{"up", "card-87"}) {
		t.Fatalf("res = %+v, commands = %+v", res, specs)
	}
}

func TestAnEnvCommandEndsWithItsTimeout(t *testing.T) {
	e := liveEnv(t, "implement")
	e.cmds.block = make(chan struct{})
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Millisecond)
	defer cancel()

	res := e.h.router.runEnv(ctx, opRefresh, e.session)
	close(e.cmds.block)

	if res.OK || !strings.Contains(res.Problem, "killed") {
		t.Fatalf("res = %+v", res)
	}
}

func envSocket(t *testing.T, env func(context.Context, string, string) envResult) string {
	t.Helper()
	shortConfigHome(t)
	path := testSocket(t)
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := serveControl(ctx, ln, controlOps{env: env})
	t.Cleanup(func() { cancel(); <-done })

	return path
}

func TestTheControlSocketAnswersARefreshAndAReset(t *testing.T) {
	var mu sync.Mutex
	var calls []string
	path := envSocket(t, func(_ context.Context, op, session string) envResult {
		mu.Lock()
		defer mu.Unlock()
		calls = append(calls, op+" "+session)

		return envResult{OK: true, Ran: true}
	})

	for _, op := range []string{opRefresh, opReset} {
		res, err := requestEnv(path, op, "s1")
		if err != nil || !res.OK || !res.Ran {
			t.Fatalf("%s: res = %+v, err = %v", op, res, err)
		}
	}
	mu.Lock()
	defer mu.Unlock()
	if !slices.Equal(calls, []string{"refresh s1", "reset s1"}) {
		t.Fatalf("calls = %v", calls)
	}
}

func TestTheControlSocketRefusesAnEnvRequestWithNoSession(t *testing.T) {
	path := envSocket(t, func(context.Context, string, string) envResult {
		t.Error("the handler ran for a request with no session")

		return envResult{OK: true}
	})

	res, err := requestEnv(path, opRefresh, "")
	if err != nil || res.OK || res.Problem == "" {
		t.Fatalf("res = %+v, err = %v", res, err)
	}
}

func TestAnEnvOpIsUnknownWithoutAHandler(t *testing.T) {
	shortConfigHome(t)
	path := testSocket(t)
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := serveControl(ctx, ln, controlOps{})
	t.Cleanup(func() { cancel(); <-done })

	if res := askControl(t, path, `{"op":"refresh","sessionId":"s1"}`+"\n"); res.OK || len(res.Problems) != 1 {
		t.Fatalf("res = %+v", res)
	}
}

func runEnvCmd(t *testing.T, op string) error {
	t.Helper()
	cmd := newEnvCmd()
	cmd.SetArgs([]string{op})
	cmd.SilenceErrors, cmd.SilenceUsage = true, true
	cmd.SetOut(&strings.Builder{})

	return cmd.Execute()
}

func envExitCode(t *testing.T, err error) int {
	t.Helper()
	code, ok := ExitCode(err)
	if !ok {
		t.Fatalf("err = %v, want an exit code", err)
	}

	return code
}

func TestEnvExitsWithTwoOutsideABridgeWorker(t *testing.T) {
	for name, env := range map[string][2]string{
		"no socket":  {"", "s1"},
		"no session": {"/tmp/x.sock", ""},
	} {
		t.Run(name, func(t *testing.T) {
			t.Setenv(socketEnv, env[0])
			t.Setenv(sessionEnv, env[1])

			if got := envExitCode(t, runEnvCmd(t, opRefresh)); got != exitNotBridgeRun {
				t.Fatalf("exit = %d", got)
			}
		})
	}
}

func TestEnvExitsWithTwoWhenNoBridgeListens(t *testing.T) {
	t.Setenv(socketEnv, filepath.Join(t.TempDir(), "gone.sock"))
	t.Setenv(sessionEnv, "s1")

	if got := envExitCode(t, runEnvCmd(t, opRefresh)); got != exitNotBridgeRun {
		t.Fatalf("exit = %d", got)
	}
}

func TestEnvExitCodesFollowTheAnswer(t *testing.T) {
	for name, tc := range map[string]struct {
		res  envResult
		want int
	}{
		"ran":         {envResult{OK: true, Ran: true}, 0},
		"no command":  {envResult{OK: true}, 0},
		"failed":      {envResult{Ran: true, Problem: "the command exited with code 3\nboom"}, exitEnvFailed},
		"not worker":  {envResult{NotWorker: true, Problem: "no worker"}, exitNotBridgeRun},
		"busy":        {envResult{Problem: "another refresh or reset runs for this session"}, exitEnvFailed},
		"empty fault": {envResult{}, exitEnvFailed},
	} {
		t.Run(name, func(t *testing.T) {
			path := envSocket(t, func(context.Context, string, string) envResult { return tc.res })
			t.Setenv(socketEnv, path)
			t.Setenv(sessionEnv, "s1")

			err := runEnvCmd(t, opReset)

			if tc.want == 0 {
				if err != nil {
					t.Fatalf("err = %v", err)
				}

				return
			}
			if got := envExitCode(t, err); got != tc.want {
				t.Fatalf("exit = %d, err = %v", got, err)
			}
			if tc.res.Problem != "" && !strings.Contains(err.Error(), tc.res.Problem) {
				t.Fatalf("err = %q, want %q in it", err, tc.res.Problem)
			}
		})
	}
}

func TestEnvRefusesAnArgument(t *testing.T) {
	cmd := newEnvCmd()
	cmd.SetArgs([]string{"refresh", "extra"})
	cmd.SilenceErrors, cmd.SilenceUsage = true, true
	if err := cmd.Execute(); err == nil {
		t.Fatal("env refresh took an argument")
	}
}

// requestEnv sends the session and the op on one JSON line.
func TestRequestEnvSendsOneJSONLine(t *testing.T) {
	shortConfigHome(t)
	path := testSocket(t)
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	got := make(chan map[string]string, 1)
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		buf := make([]byte, 512)
		n, _ := conn.Read(buf)
		var req map[string]string
		_ = json.Unmarshal(buf[:n], &req)
		got <- req
		conn.Write([]byte(`{"ok":true}` + "\n"))
	}()

	if _, err := requestEnv(path, opReset, "s9"); err != nil {
		t.Fatal(err)
	}
	if req := <-got; req["op"] != "reset" || req["sessionId"] != "s9" {
		t.Fatalf("request = %v", req)
	}
}

// Only the error of this package sets an exit status, so a wrapped error of a
// child process exits with 1.
func TestExitCodeIgnoresAnyOtherErrorWithACode(t *testing.T) {
	cause := exec.Command("sh", "-c", "exit 7").Run()
	if _, ok := ExitCode(cause); ok {
		t.Fatalf("ExitCode took %v", cause)
	}
	if code, ok := ExitCode(fmt.Errorf("wrapped: %w", &exitError{3, errors.New("x")})); !ok || code != 3 {
		t.Fatalf("code = %d, ok = %v", code, ok)
	}
}

func TestEnvPrintsTheOutputOfASuccess(t *testing.T) {
	path := envSocket(t, func(context.Context, string, string) envResult {
		return envResult{OK: true, Ran: true, Output: "built 3 files"}
	})
	t.Setenv(socketEnv, path)
	t.Setenv(sessionEnv, "s1")
	out := &strings.Builder{}
	cmd := newEnvCmd()
	cmd.SetArgs([]string{opRefresh})
	cmd.SetOut(out)

	if err := cmd.Execute(); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out.String(), "built 3 files") {
		t.Fatalf("out = %q", out.String())
	}
}
