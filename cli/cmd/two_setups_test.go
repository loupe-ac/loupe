//go:build unix

package cmd

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/zalando/go-keyring"
)

func TestWorkerEnvKeepsTheSetup(t *testing.T) {
	env := workerEnv([]string{"LOUPE_CONFIG=/some setup", "PATH=/bin"}, "s1", "", nil, nil)
	if !contains(env, "LOUPE_CONFIG=/some setup") {
		t.Fatalf("env = %q", env)
	}
}

func contains(env []string, want string) bool {
	for _, e := range env {
		if e == want {
			return true
		}
	}

	return false
}

func TestLaunchEnvExportsTheSetupWithAQuotedPath(t *testing.T) {
	t.Cleanup(func() { _, _ = config.Resolve("") })

	_, _ = config.Resolve("")
	if got := withSetupEnv([]string{"A=1"}); len(got) != 1 {
		t.Fatalf("default setup env = %q", got)
	}

	_, _ = config.Resolve("/tmp/my setup")
	got := withSetupEnv([]string{"A=1", "LOUPE_CONFIG=/old"})
	if len(got) != 2 || got[1] != "LOUPE_CONFIG=/tmp/my setup" {
		t.Fatalf("env = %q", got)
	}
}

func TestListenControlNamesAShorterSetup(t *testing.T) {
	t.Cleanup(func() { _, _ = config.Resolve("") })
	long := "/tmp/" + strings.Repeat("d", 100)
	_, _ = config.Resolve(long)
	sock, err := socketPath(filepath.Join(long, "rules.yaml"))
	if err != nil {
		t.Fatal(err)
	}

	_, err = listenControl(sock)
	if err == nil || !strings.Contains(err.Error(), "use a shorter setup folder than "+long) {
		t.Fatalf("err = %v", err)
	}
	if _, statErr := os.Stat(long); statErr == nil {
		t.Fatal("the refusal must come before any folder is made")
	}
}

type bridgeFiles struct {
	dir, lock, sock, id, log, startLog, scripts string
}

// Two setups each hold their own files, and stopping one leaves the other.
func TestTwoSetupsKeepSeparateBridges(t *testing.T) {
	keyring.MockInit()
	t.Cleanup(func() { _, _ = config.Resolve("") })
	shared, err := os.MkdirTemp("/tmp", "lb")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(shared) })
	t.Setenv("TMPDIR", shared)
	t.Setenv("HOME", shared)
	t.Setenv("XDG_CONFIG_HOME", shared)
	t.Setenv("LOUPE_CONFIG", "")

	files := map[string]*bridgeFiles{}
	for _, dir := range []string{filepath.Join(shared, "set up a"), filepath.Join(shared, "b")} {
		if _, err := config.Resolve(dir); err != nil {
			t.Fatal(err)
		}
		cfg := config.Config{BaseURL: "https://loupe.test", OAuth: &config.OAuthTokens{AccessToken: "a", RefreshToken: "r", ExpiresAt: time.Now().Add(time.Hour)}}
		if err := config.Save(cfg); err != nil {
			t.Fatal(err)
		}
		f := &bridgeFiles{dir: dir}
		if f.id, err = config.EnsureBridgeID(); err != nil {
			t.Fatal(err)
		}
		rules, err := defaultRulesPath()
		if err != nil {
			t.Fatal(err)
		}
		if f.sock, err = socketPath(rules); err != nil {
			t.Fatal(err)
		}
		lock, err := lockBridge(rules, f.sock)
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() { lock.Close() })
		f.lock = lock.path
		ln, err := listenControl(f.sock)
		if err != nil {
			t.Fatal(err)
		}
		ctx, cancel := context.WithCancel(context.Background())
		done := serveControl(ctx, ln, controlOps{stop: func() {
			cancel()
			lock.Close()
		}})
		t.Cleanup(func() {
			cancel()
			<-done
		})

		f.log = defaultLogPath()
		if err := os.WriteFile(f.log, []byte("log\n"), 0o600); err != nil {
			t.Fatal(err)
		}
		tmp, err := config.TempDir()
		if err != nil {
			t.Fatal(err)
		}
		start, err := os.CreateTemp(tmp, "loupe-bridge-start-*")
		if err != nil {
			t.Fatal(err)
		}
		start.Close()
		f.startLog = start.Name()
		script, err := writeLaunchScript(defaultScriptDir(), "s1", "true\n")
		if err != nil {
			t.Fatal(err)
		}
		f.scripts = filepath.Dir(script)
		files[dir] = f
	}

	var all []*bridgeFiles
	for _, f := range files {
		all = append(all, f)
	}
	a, b := all[0], all[1]
	if a.id == b.id {
		t.Fatal("the two setups share a bridge id")
	}
	for _, f := range all {
		for name, p := range map[string]string{"lock": f.lock, "socket": f.sock, "log": f.log, "start log": f.startLog, "launch scripts": f.scripts} {
			if !strings.HasPrefix(p, f.dir+string(filepath.Separator)) {
				t.Errorf("%s %s is outside the setup %s", name, p, f.dir)
			}
		}
	}
	if entries, _ := os.ReadDir(shared); len(entries) != 2 {
		t.Fatalf("the shared temp folder holds %d entries, want the two setups only", len(entries))
	}

	var withSpace, other *bridgeFiles
	for _, f := range all {
		if strings.Contains(f.dir, " ") {
			withSpace = f
		} else {
			other = f
		}
	}
	if _, err := config.Resolve(withSpace.dir); err != nil {
		t.Fatal(err)
	}
	if _, err := stopCmd(t); err != nil {
		t.Fatalf("bridge stop: %v", err)
	}
	if controlAnswers(withSpace.sock) {
		t.Fatal("the stopped bridge still answers")
	}
	if !controlAnswers(other.sock) {
		t.Fatal("stopping one bridge stopped the other")
	}
}
