package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

// runningBridge starts a bridge for rulesPath that answers with ops. It holds
// the lock and listens on the control socket until the test ends.
func fakeBridge(t *testing.T, rulesPath string, ops controlOps) string {
	t.Helper()
	abs, err := filepath.Abs(rulesPath)
	if err != nil {
		t.Fatal(err)
	}
	sock, err := socketPath(abs)
	if err != nil {
		t.Fatal(err)
	}
	lock, err := lockBridge(abs, sock)
	if err != nil {
		t.Fatal(err)
	}
	ln, err := listenControl(sock)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := serveControl(ctx, ln, ops)
	t.Cleanup(func() {
		cancel()
		<-done
		lock.Close()
	})

	return sock
}

func statusOps(res statusResult) controlOps {
	return controlOps{status: func() statusResult { return res }}
}

func validStatus(name string) statusResult {
	beat := time.Date(2026, 10, 30, 9, 0, 0, 0, time.UTC)

	return statusResult{
		Name: name, RulesPath: "/home/me/" + name + ".yaml", PID: 4242, Version: "1.2.3",
		LogFile: "/home/me/bridge.log", Login: loginValid, LastHeartbeatAt: &beat,
		Workers: []statusWorker{{Card: 12, Kind: "implement", StartedAt: time.Date(2026, 10, 30, 8, 30, 0, 0, time.UTC)}},
	}
}

func runBridgeStatus(t *testing.T, args ...string) (string, error) {
	t.Helper()
	var out, errOut bytes.Buffer
	cmd := newRootCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&errOut)
	cmd.SetArgs(append([]string{"bridge", "status"}, args...))
	err := cmd.Execute()

	return out.String(), err
}

func TestStatusOpAnswersOverTheSocket(t *testing.T) {
	shortConfigHome(t)
	h := newHarness(t)
	h.router.rulesPath, h.router.logPath = "/home/me/rules.yaml", "/home/me/bridge.log"
	log, last := newTrackedLogger(&bytes.Buffer{})
	h.router.lastError = last
	log.Info("fine", "message", "not an error")
	log.Warn("heartbeat_failed", "error", "boom")
	beat := time.Now()
	hb := &heartbeater{now: func() time.Time { return beat }, log: log}
	hb.record(nil)
	h.router.heartbeat = hb
	began := time.Date(2026, 10, 30, 8, 0, 0, 0, time.UTC)
	h.router.mu.Lock()
	h.router.trackLocked(liveRun{p: pending{runID: "r1", work: api.WorkRequest{Kind: "plan", CardNumber: 7}}, began: began})
	h.router.mu.Unlock()

	sock := fakeBridge(t, "rules.yaml", controlOps{status: h.router.status})
	res, err := requestStatus(sock)
	if err != nil {
		t.Fatal(err)
	}
	if res.RulesPath != "/home/me/rules.yaml" || res.LogFile != "/home/me/bridge.log" || res.PID == 0 || res.Version == "" || res.Login != loginValid {
		t.Fatalf("status = %+v", res)
	}
	if res.LastHeartbeatAt == nil || !res.LastHeartbeatAt.Equal(beat) {
		t.Fatalf("last heartbeat = %v, want %v", res.LastHeartbeatAt, beat)
	}
	if res.LastError == nil || res.LastError.Event != "heartbeat_failed" || res.LastError.Message != "boom" || res.LastError.Time.IsZero() {
		t.Fatalf("last error = %+v", res.LastError)
	}
	if len(res.Workers) != 1 || res.Workers[0].Card != 7 || res.Workers[0].Kind != "plan" || !res.Workers[0].StartedAt.Equal(began) {
		t.Fatalf("workers = %+v", res.Workers)
	}
}

func TestStatusOpAnswerCarriesNoToken(t *testing.T) {
	shortConfigHome(t)
	h := newHarness(t)
	sock := fakeBridge(t, "rules.yaml", controlOps{status: h.router.status})
	conn, err := net.Dial("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	conn.Write([]byte(`{"op":"status"}` + "\n"))
	var raw map[string]any
	if err := json.NewDecoder(conn).Decode(&raw); err != nil {
		t.Fatal(err)
	}
	for key := range raw {
		if strings.Contains(strings.ToLower(key), "token") {
			t.Fatalf("the answer has the key %q", key)
		}
	}
	if workers, ok := raw["workers"].([]any); !ok || len(workers) != 0 {
		t.Fatalf("workers = %v, want an empty list", raw["workers"])
	}
}

func TestStatusOpReportsAnExpiredLogin(t *testing.T) {
	h := newHarness(t)
	g := &loginGate{tripped: true}
	setBridgeGate(g)
	defer setBridgeGate(nil)
	if got := h.router.status().Login; got != loginExpired {
		t.Fatalf("login = %q, want %q", got, loginExpired)
	}
	g.tripped = false
	if got := h.router.status().Login; got != loginValid {
		t.Fatalf("login = %q, want %q", got, loginValid)
	}
}

func TestAnOlderBridgeRefusesTheStatusOp(t *testing.T) {
	sock := testSocket(t)
	ln, err := listenControl(sock)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := serveControl(ctx, ln, controlOps{})
	defer func() { cancel(); <-done }()
	res, err := requestStatus(sock)
	if err != nil || len(res.Problems) != 1 || !strings.Contains(res.Problems[0], unknownStatusOp) {
		t.Fatalf("res = %+v, err = %v", res, err)
	}
}

func TestLastErrorKeepsTheLastRecordAtWarnOrAbove(t *testing.T) {
	log, last := newTrackedLogger(&bytes.Buffer{})
	if last.get() != nil {
		t.Fatal("a fresh tracker holds an error")
	}
	log.Warn("first", "message", "one")
	log.With("k", "v").WithGroup("g").Error("second", "error", "two")
	log.Info("third", "message", "ignored")
	log.Log(context.Background(), slog.LevelDebug, "fourth")
	got := last.get()
	if got == nil || got.Event != "second" || got.Message != "two" || got.Time.IsZero() {
		t.Fatalf("last = %+v", got)
	}
}

func TestBridgeStatusSaysWhenNoBridgeRuns(t *testing.T) {
	shortConfigHome(t)
	out, err := runBridgeStatus(t)
	want := "No bridge is running.\nStart one with `loupe bridge run -d`.\nSee why the last bridge stopped with `loupe bridge logs`.\n"
	if err == nil || out != want {
		t.Fatalf("out = %q, err = %v", out, err)
	}
}

func TestBridgeStatusShowsAValidBridge(t *testing.T) {
	shortConfigHome(t)
	fakeBridge(t, "rules.yaml", statusOps(validStatus("laptop")))
	out, err := runBridgeStatus(t)
	if err != nil {
		t.Fatalf("err = %v", err)
	}
	for _, want := range []string{
		"laptop\n", "Rule file:   /home/me/laptop.yaml", "PID:         4242", "CLI version: 1.2.3",
		"Log file:    /home/me/bridge.log", "Login:       valid", "last accepted at 2026-10-30T09:00:00Z",
		"Last error:  none", "card 12, implement, started at 2026-10-30T08:30:00Z",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestBridgeStatusFailsOnAnExpiredLogin(t *testing.T) {
	shortConfigHome(t)
	res := validStatus("laptop")
	res.Login = loginExpired
	res.LastError = &statusError{Event: "login_expired", Message: "Run `loupe login`.", Time: time.Date(2026, 10, 30, 7, 0, 0, 0, time.UTC)}
	fakeBridge(t, "rules.yaml", statusOps(res))
	out, err := runBridgeStatus(t)
	if !errors.Is(err, errLoginExpired) {
		t.Fatalf("err = %v", err)
	}
	for _, want := range []string{"expired, waiting for `loupe login`", "login_expired: Run `loupe login`. (2026-10-30T07:00:00Z)"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestBridgeStatusExplainsAnOlderBridge(t *testing.T) {
	shortConfigHome(t)
	sock := fakeBridge(t, "rules.yaml", controlOps{})
	out, err := runBridgeStatus(t)
	if err != nil {
		t.Fatalf("err = %v", err)
	}
	if !strings.HasPrefix(out, sock+"\n") || !strings.Contains(out, "older CLI") || !strings.Contains(out, "`loupe update`") || strings.Contains(out, "PID") {
		t.Fatalf("out = %q", out)
	}
}

func TestBridgeStatusSkipsALockWhoseSocketIsGone(t *testing.T) {
	shortConfigHome(t)
	abs, _ := filepath.Abs("rules.yaml")
	sock, _ := socketPath(abs)
	lock, err := lockBridge(abs, sock)
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	out, err := runBridgeStatus(t)
	if err == nil || !strings.HasPrefix(out, "No bridge is running.") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
}

func TestBridgeStatusPicksABridgeByName(t *testing.T) {
	shortConfigHome(t)
	fakeBridge(t, "one.yaml", statusOps(validStatus("alpha")))
	expired := validStatus("beta")
	expired.Login = loginExpired
	fakeBridge(t, "two.yaml", statusOps(expired))

	out, err := runBridgeStatus(t, "--bridge", "alpha")
	if err != nil || strings.Contains(out, "beta") || !strings.Contains(out, "alpha") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
	if _, err := runBridgeStatus(t, "--bridge", "beta"); !errors.Is(err, errLoginExpired) {
		t.Fatalf("err = %v", err)
	}
	if _, err := runBridgeStatus(t); !errors.Is(err, errLoginExpired) {
		t.Fatalf("both: err = %v", err)
	}
	if _, err := runBridgeStatus(t, "--bridge", "gamma"); err == nil || !strings.Contains(err.Error(), `"gamma"`) {
		t.Fatalf("err = %v", err)
	}
}

func TestBridgeStatusRefusesASharedName(t *testing.T) {
	shortConfigHome(t)
	fakeBridge(t, "one.yaml", statusOps(validStatus("twin")))
	fakeBridge(t, "two.yaml", statusOps(validStatus("twin")))
	_, err := runBridgeStatus(t, "--bridge", "twin")
	want := "Two running bridges are named \"twin\". Set `name:` in one rule file to tell them apart."
	if err == nil || err.Error() != want {
		t.Fatalf("err = %v, want %q", err, want)
	}
}

func TestStatusBridgesLine(t *testing.T) {
	shortConfigHome(t)
	check := func(want string, wantErr bool) {
		t.Helper()
		var out bytes.Buffer
		err := checkStatusBridges(&out)
		if out.String() != "Bridges:     "+want+"\n" || (err != nil) != wantErr {
			t.Fatalf("out = %q, err = %v, want %q", out.String(), err, want)
		}
	}
	check("none running.", false)

	valid := fakeBridge(t, "one.yaml", statusOps(validStatus("alpha")))
	check("1 running, login valid.", false)
	_ = valid

	expired := validStatus("beta")
	expired.Login = loginExpired
	fakeBridge(t, "two.yaml", statusOps(expired))
	check("2 running, login expired. See `loupe bridge status`.", true)
}
