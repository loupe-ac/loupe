//go:build unix

package cmd

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
)

// workerSees makes a claude that reports what a credentialed worker sees.
func workerSees(t *testing.T) {
	t.Helper()
	workerClaude(t, "echo \"run=$LOUPE_RUN_ID sock=$LOUPE_CONTROL_SOCKET gh=$GH_TOKEN prompt=$GIT_TERMINAL_PROMPT count=$GIT_CONFIG_COUNT wrapper=$(command -v gh)\" >&2\nexit 1\n")
}

func TestAManagedWorkerGetsTheHelperAndTheWrapper(t *testing.T) {
	workerSees(t)
	f := newTokenFixture()
	f.broker.socket = "/the/socket"
	var seen bool
	spec := workerSpec{dir: t.TempDir(), sessionID: testSession, runID: "run-1", prompt: "go", credentials: f.broker, project: "p1"}

	res := runWorker(context.Background(), spec, func(workerProc) {
		f.broker.mu.Lock()
		defer f.broker.mu.Unlock()
		run, ok := f.broker.runs["run-1"]
		seen = ok && run.handle == "p1" && !run.agent && run.dir == spec.dir
	})
	if res.err != nil {
		t.Fatalf("runWorker = %+v", res)
	}
	if !seen {
		t.Fatal("the run was not registered while it ran")
	}
	if len(f.broker.runs) != 0 {
		t.Fatalf("runs = %v after the worker ended", f.broker.runs)
	}
	runs, _ := config.RunsDir()
	want := "run=run-1 sock=/the/socket gh= prompt=0 count=4 wrapper=" + filepath.Join(runs, "run-1", "bin", "gh")
	if res.output != want {
		t.Fatalf("output = %q, want %q", res.output, want)
	}
	rec, err := readRunRecord(res.dir)
	if err != nil || !rec.Managed || rec.Agent || rec.Project != "p1" {
		t.Fatalf("record = %+v, %v", rec, err)
	}
	if _, err := os.Stat(filepath.Join(res.dir, "bin", "gh")); err != nil {
		t.Fatal(err)
	}
}

func TestAWorkerOfAnUnmanagedProjectKeepsTheMachineSetup(t *testing.T) {
	for name, cause := range map[string]error{"not managed": api.ErrGitIdentityNotManaged, "unsupported": api.ErrGitTokenUnsupported} {
		t.Run(name, func(t *testing.T) {
			workerSees(t)
			f := newTokenFixture()
			f.failWith(cause)

			res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, runID: "run-1", prompt: "go", credentials: f.broker, project: "p1"}, nil)
			if res.err != nil || strings.Contains(res.output, "run=run-1") || !strings.HasPrefix(res.output, "run= sock= gh= prompt= count= wrapper=") {
				t.Fatalf("runWorker = %+v", res)
			}
			if _, err := os.Stat(filepath.Join(res.dir, "bin")); err == nil {
				t.Fatal("an unmanaged run got a wrapper")
			}
			if rec, _ := readRunRecord(res.dir); rec.Managed || rec.Agent {
				t.Fatalf("record = %+v", rec)
			}
		})
	}
}

func TestAWorkerWhoseAskFailsIsManagedAndFailsClosed(t *testing.T) {
	workerSees(t)
	f := newTokenFixture()
	f.failWith(errors.New("HTTP 502: github_unavailable"))

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, runID: "run-1", prompt: "go", credentials: f.broker, project: "p1"}, nil)
	if !strings.HasPrefix(res.output, "run=run-1 ") {
		t.Fatalf("runWorker = %+v", res)
	}
	if rec, _ := readRunRecord(res.dir); !rec.Managed {
		t.Fatalf("record = %+v", rec)
	}
}

func TestAnAgentWorkerGetsTheHelperWithoutAServerCall(t *testing.T) {
	workerSees(t)
	f := newTokenFixture()
	f.broker.agent = &config.AgentAccount{Token: "ghp_agent", Login: "loupe-bot", ID: 7}

	res := runWorker(context.Background(), workerSpec{
		dir: t.TempDir(), sessionID: testSession, runID: "run-1", prompt: "go", credentials: f.broker, project: "p1", agent: f.broker.agent,
	}, nil)
	if !strings.HasPrefix(res.output, "run=run-1 ") || strings.Contains(res.output, "ghp_agent") || f.calls.Load() != 0 {
		t.Fatalf("runWorker = %+v after %d calls", res, f.calls.Load())
	}
	if rec, _ := readRunRecord(res.dir); !rec.Agent || rec.Managed {
		t.Fatalf("record = %+v", rec)
	}
}

func TestAnAgentWorkerWithNoBrokerDoesNotStart(t *testing.T) {
	workerSees(t)
	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: "go", agent: &config.AgentAccount{Token: "t", Login: "l"}}, nil)
	if res.err == nil {
		t.Fatalf("runWorker = %+v, want an error", res)
	}
}
