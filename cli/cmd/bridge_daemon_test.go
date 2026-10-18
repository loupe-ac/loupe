//go:build unix

package cmd

import (
	"bytes"
	"context"
	"os"
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

func TestBridgeStopWaitsForTheLockAfterTheSocketCloses(t *testing.T) {
	shortConfigHome(t)
	abs, _ := filepath.Abs("rules.yaml")
	path, err := socketPath(abs)
	if err != nil {
		t.Fatal(err)
	}
	lock, err := lockBridge(abs, path)
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := serveControl(ctx, ln, controlOps{stop: cancel})

	returned := make(chan error, 1)
	go func() {
		_, err := stopCmd(t, "--rules", "rules.yaml")
		returned <- err
	}()
	<-done
	select {
	case err := <-returned:
		t.Fatalf("stop returned while the bridge held its lock: %v", err)
	case <-time.After(500 * time.Millisecond):
	}
	lock.Close()
	select {
	case err := <-returned:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("stop did not return after the lock was released")
	}
}

func TestBridgeStopWaitsForTheLockOfTheBridgeAfterItsSymlinkMoved(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	link := filepath.Join(dir, "rules.yaml")
	for _, name := range []string{"a.yaml", "b.yaml"} {
		if err := os.WriteFile(filepath.Join(dir, name), nil, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.Symlink(filepath.Join(dir, "a.yaml"), link); err != nil {
		t.Fatal(err)
	}
	path, err := socketPath(link)
	if err != nil {
		t.Fatal(err)
	}
	lock, err := lockBridge(link, path)
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := serveControl(ctx, ln, controlOps{stop: cancel})
	if err := os.Remove(link); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(dir, "b.yaml"), link); err != nil {
		t.Fatal(err)
	}

	returned := make(chan error, 1)
	go func() {
		_, err := stopCmd(t, "--rules", link)
		returned <- err
	}()
	<-done
	select {
	case err := <-returned:
		t.Fatalf("stop returned while the bridge held its lock: %v", err)
	case <-time.After(500 * time.Millisecond):
	}
	lock.Close()
	select {
	case err := <-returned:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("stop did not return after the lock was released")
	}
}

func TestBridgeStopWaitsForTheLockThatAReloadMovedAfterTheStop(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	link := filepath.Join(dir, "rules.yaml")
	for _, name := range []string{"a.yaml", "b.yaml"} {
		if err := os.WriteFile(filepath.Join(dir, name), nil, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.Symlink(filepath.Join(dir, "a.yaml"), link); err != nil {
		t.Fatal(err)
	}
	path, err := socketPath(link)
	if err != nil {
		t.Fatal(err)
	}
	lock, err := lockBridge(link, path)
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	moved := make(chan error, 1)
	done := serveControl(ctx, ln, controlOps{stop: func() {
		// A reload that runs when the stop arrives moves the lock to b.yaml.
		os.Remove(link)
		os.Symlink(filepath.Join(dir, "b.yaml"), link)
		_, apply, err := lock.follow()
		if err == nil {
			apply(true)
		}
		moved <- err
		cancel()
	}})

	returned := make(chan error, 1)
	go func() {
		_, err := stopCmd(t, "--rules", link)
		returned <- err
	}()
	if err := <-moved; err != nil {
		t.Fatal(err)
	}
	<-done
	select {
	case err := <-returned:
		t.Fatalf("stop returned while the bridge held its moved lock: %v", err)
	case <-time.After(500 * time.Millisecond):
	}
	lock.Close()
	select {
	case err := <-returned:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("stop did not return after the lock was released")
	}
}

// A move that runs between the listing of the lock files and their checks
// waits for the scan, so the scan still sees the old lock.
func TestHeldBridgesSeesALockThatMovesDuringTheScan(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	link := filepath.Join(dir, "rules.yaml")
	for _, name := range []string{"a.yaml", "b.yaml"} {
		if err := os.WriteFile(filepath.Join(dir, name), nil, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.Symlink(filepath.Join(dir, "a.yaml"), link); err != nil {
		t.Fatal(err)
	}
	sock, err := socketPath(link)
	if err != nil {
		t.Fatal(err)
	}
	lock, err := lockBridge(link, sock)
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	if err := os.Remove(link); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(dir, "b.yaml"), link); err != nil {
		t.Fatal(err)
	}

	moved := make(chan error, 1)
	afterLockGlob = func() {
		afterLockGlob = func() {}
		go func() {
			_, apply, err := lock.follow()
			if err == nil {
				apply(true)
			}
			moved <- err
		}()
		select {
		case err := <-moved:
			moved <- err
		case <-time.After(300 * time.Millisecond):
		}
	}
	t.Cleanup(func() { afterLockGlob = func() {} })

	locks, err := locksNaming(sock)
	if err != nil {
		t.Fatal(err)
	}
	if len(locks) == 0 {
		t.Fatal("the scan missed the lock that moved while it ran")
	}
	if err := <-moved; err != nil {
		t.Fatal(err)
	}
	if locks, err := locksNaming(sock); err != nil || len(locks) != 1 || locks[0] != lock.path {
		t.Fatalf("locks after the move = %v, %v; want %s", locks, err, lock.path)
	}
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
