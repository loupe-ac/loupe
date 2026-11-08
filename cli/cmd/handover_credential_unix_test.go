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
// update prunes the version folders, and the gh wrapper of the worker still
// gets a token from the installed binary.
func TestAWorkerThatLivesThroughTwoUpdatesStillGetsAToken(t *testing.T) {
	root := t.TempDir()
	installed := filepath.Join(root, "bin", "loupe")
	writeScript(t, installed, "echo token-from-installed\n")
	for _, v := range []string{"1.0.0", "2.0.0", "3.0.0"} {
		writeScript(t, filepath.Join(root, "versions", v, "loupe"), "echo token-from-"+v+"\n")
	}
	realBin := filepath.Join(root, "real")
	writeScript(t, filepath.Join(realBin, "gh"), "echo \"gh got $GH_TOKEN\"\n")

	f := newTokenFixture()
	h := newHarness(t)
	h.router.credentials = f.broker
	useInstalledBinary(h.router, &bridgeUpdate{target: installed})

	spec := workerSpec{dir: t.TempDir(), runDir: t.TempDir(), runID: "run-9", credentials: f.broker, project: "p1"}
	creds, _, err := spec.credentialEnv(context.Background())
	if err != nil {
		t.Fatal(err)
	}

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

	cmd := exec.Command(filepath.Join(creds.binDir, "gh"))
	cmd.Env = append(os.Environ(), "PATH="+realBin+":"+os.Getenv("PATH"))
	out, err := cmd.CombinedOutput()
	if err != nil || !strings.Contains(string(out), "gh got token-from-installed") {
		t.Fatalf("gh wrapper = %q, %v", out, err)
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
