//go:build unix

package cmd

import (
	"bytes"
	"context"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"
)

func TestServeControlStopsTheBridgeAfterItsAnswer(t *testing.T) {
	path := testSocket(t)
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	stopped := make(chan struct{})
	done := serveControl(ctx, ln, controlOps{stop: func() { close(stopped); cancel() }})

	if res := askControl(t, path, `{"op":"stop"}`+"\n"); !res.OK {
		t.Fatalf("res = %+v", res)
	}
	select {
	case <-stopped:
	case <-time.After(5 * time.Second):
		t.Fatal("stop did not run")
	}
	<-done
	if controlAnswers(path) {
		t.Fatal("the socket still answers")
	}
}

func TestServeControlRefusesStopWhenTheBridgeCannotStop(t *testing.T) {
	path, _, _ := serveTest(t, func(context.Context) reloadResult { return reloadResult{OK: true} })

	if res := askControl(t, path, `{"op":"stop"}`+"\n"); res.OK {
		t.Fatalf("res = %+v", res)
	}
}

func stopCmd(t *testing.T, args ...string) (string, error) {
	t.Helper()
	root := newRootCmd()
	var out bytes.Buffer
	root.SetOut(&out)
	root.SetErr(&out)
	root.SetArgs(append([]string{"bridge", "stop"}, args...))
	err := root.Execute()

	return out.String(), err
}

func TestBridgeStopNeedsARunningBridge(t *testing.T) {
	shortConfigHome(t)
	abs, _ := filepath.Abs("rules.yaml")

	_, err := stopCmd(t, "--rules", "rules.yaml")
	if err == nil || err.Error() != "no running bridge reads "+abs {
		t.Fatalf("err = %v", err)
	}
}

func TestBridgeStopWaitsForTheBridgeToExit(t *testing.T) {
	shortConfigHome(t)
	abs, _ := filepath.Abs("rules.yaml")
	path, err := socketPath(abs)
	if err != nil {
		t.Fatal(err)
	}
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := serveControl(ctx, ln, controlOps{stop: cancel})

	out, err := stopCmd(t, "--rules", "rules.yaml")
	if err != nil {
		t.Fatal(err)
	}
	if want := "stopped the bridge that reads " + abs + "\n"; out != want {
		t.Fatalf("out = %q, want %q", out, want)
	}
	<-done
}

func TestDaemonArgsKeepTheFlagsThatWereSetAndDropTheDaemonFlag(t *testing.T) {
	cmd := newBridgeRunCmd()
	if err := cmd.ParseFlags([]string{"-d", "--rules", "r.yaml", "--model", "opus"}); err != nil {
		t.Fatal(err)
	}

	got := daemonArgs(cmd.Flags())
	want := []string{"bridge", "run", "--model=opus", "--rules=r.yaml"}
	if !slices.Equal(got, want) {
		t.Fatalf("args = %v, want %v", got, want)
	}
}

func TestStartDaemonReportsABridgeThatIsAlreadyRunning(t *testing.T) {
	shortConfigHome(t)
	abs, _ := filepath.Abs("rules.yaml")
	path, err := socketPath(abs)
	if err != nil {
		t.Fatal(err)
	}
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := serveControl(ctx, ln, controlOps{})
	t.Cleanup(func() { cancel(); <-done })

	root := newRootCmd()
	var out bytes.Buffer
	root.SetOut(&out)
	root.SetArgs([]string{"bridge", "run", "-d", "--rules", "rules.yaml"})
	if err := root.Execute(); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out.String(), "the bridge already runs") {
		t.Fatalf("out = %q", out.String())
	}
}
