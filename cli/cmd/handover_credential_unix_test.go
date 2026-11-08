//go:build unix

package cmd

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

func writeScript(t *testing.T, path, body string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte("#!/bin/sh\n"+body), 0o755); err != nil {
		t.Fatal(err)
	}
}

// A worker started under 1.0.0 outlives the updates to 2.0.0 and 3.0.0. Each
// update prunes the version folders and hands the run to a new bridge. The git
// helper and the gh wrapper of the worker still reach the broker of the
// adopting bridge through the installed binary.
func TestAWorkerThatLivesThroughTwoUpdatesStillGetsAToken(t *testing.T) {
	root := t.TempDir()
	installed := filepath.Join(root, "bin", "loupe")
	// The installed binary is this test binary running the real CLI.
	writeScript(t, installed, "LOUPE_TEST_AS_CLI=1 exec '"+os.Args[0]+"' \"$@\"\n")
	for _, v := range []string{"1.0.0", "2.0.0", "3.0.0"} {
		writeScript(t, filepath.Join(root, "versions", v, "loupe"), "echo stale-"+v+" >&2\nexit 1\n")
	}
	realBin := filepath.Join(root, "real")
	writeScript(t, filepath.Join(realBin, "gh"), "echo \"gh got $GH_TOKEN\"\n")

	f := newTokenFixture()
	f.broker.socket = testSocket(t)
	ln, err := listenControl(f.broker.socket)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := serveControl(ctx, ln, controlOps{credential: f.broker.answer})
	t.Cleanup(func() {
		cancel()
		<-done
	})
	h := newHarness(t)
	h.router.credentials = f.broker
	useInstalledBinary(h.router, &bridgeUpdate{target: installed})

	spec := workerSpec{dir: t.TempDir(), runDir: t.TempDir(), runID: "run-9", credentials: f.broker, project: "p1"}
	creds, _, err := spec.credentialEnv(ctx)
	if err != nil {
		t.Fatal(err)
	}

	// The first bridge forgets the run. Each later bridge adopts it from the
	// run record and prunes the version folders.
	run := runDir(t)
	if err := writeRunRecord(run, runRecord{Managed: true, Project: "p1", Dir: spec.dir}); err != nil {
		t.Fatal(err)
	}
	for _, v := range []string{"2.0.0", "3.0.0"} {
		forget := h.router.adoptCredentials(handoverRun{RunID: "run-9", Dir: run})
		defer forget()
		if err := pruneVersions(root, v, "3.0.0"); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := os.Stat(filepath.Join(root, "versions", "1.0.0")); !os.IsNotExist(err) {
		t.Fatalf("version 1.0.0 should be pruned, stat err = %v", err)
	}

	env := workerEnv([]string{"PATH=" + realBin + ":" + os.Getenv("PATH"), "HOME=" + t.TempDir(), "GIT_CONFIG_GLOBAL=/dev/null", "GIT_CONFIG_SYSTEM=/dev/null"}, "s", "", nil, creds)
	want := "tok-p1-acme/site-"

	git := exec.Command("git", "credential", "fill")
	git.Env = env
	git.Stdin = strings.NewReader("protocol=https\nhost=github.com\n\n")
	out, err := git.CombinedOutput()
	if err != nil || !strings.Contains(string(out), "username=x-access-token") || !strings.Contains(string(out), "password="+want) {
		t.Fatalf("git credential fill = %q, %v", out, err)
	}

	gh := exec.Command(filepath.Join(creds.binDir, "gh"))
	gh.Env = env
	out, err = gh.CombinedOutput()
	if err != nil || !strings.Contains(string(out), "gh got "+want) {
		t.Fatalf("gh wrapper = %q, %v", out, err)
	}

	// The broker must be the one that answered: a run it forgot gets no token.
	h.router.credentials.forget("run-9")
	git = exec.Command("git", "credential", "fill")
	git.Env = env
	git.Stdin = strings.NewReader("protocol=https\nhost=github.com\n\n")
	if out, err := git.CombinedOutput(); err == nil {
		t.Fatalf("git credential fill after forget = %q, want a failure", out)
	}
}

// The MCP server of a worker names the installed binary too.
func TestTheMCPConfigNamesTheInstalledBinary(t *testing.T) {
	r, _ := packageStore(t, api.Site{ID: "p1", Slug: "simple", SkillPackages: []string{"loupe"}})
	r.credentials = newTokenFixture().broker
	useInstalledBinary(r, &bridgeUpdate{target: "/installed/loupe"})

	spec := workerSpec{sessionID: "s"}
	if err := r.givePackages(&spec, pending{project: "simple"}); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(spec.mcpConfig, `"command":"/installed/loupe"`) {
		t.Fatalf("mcpConfig = %s", spec.mcpConfig)
	}
}
