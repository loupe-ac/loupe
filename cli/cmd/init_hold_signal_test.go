//go:build !windows

package cmd

import (
	"bytes"
	"context"
	"os"
	"syscall"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

type interruptingHoldClient struct {
	fakeHoldClient
}

func (c *interruptingHoldClient) ReportInteractiveLaunch(ctx context.Context, h, s string, r api.InteractiveLaunchReport) (bool, error) {
	_ = syscall.Kill(syscall.Getpid(), syscall.SIGINT)
	time.Sleep(200 * time.Millisecond)

	return c.fakeHoldClient.ReportInteractiveLaunch(ctx, h, s, r)
}

func TestInitRunClosesARunThatOpensWhileCtrlCArrives(t *testing.T) {
	useBridgeID(t, holdBridgeID, nil)
	var got os.Signal
	old := initTerminate
	initTerminate = func(sig os.Signal) { got = sig }
	t.Cleanup(func() { initTerminate = old })
	f := &interruptingHoldClient{}

	run := startInitRun(context.Background(), &bytes.Buffer{}, f, "proj-1")
	t.Cleanup(run.Close)

	if got != os.Interrupt {
		t.Errorf("terminate signal = %v, want interrupt", got)
	}
	if len(f.closes) != 1 || f.closes[0] != run.sessionID {
		t.Errorf("closes = %v, want one close of %s", f.closes, run.sessionID)
	}
}
