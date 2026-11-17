package cmd

import (
	"os"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/envallow"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

func TestWorkerEnvDropsTheSSHAgentForABridgeCredentialWorker(t *testing.T) {
	environ := []string{"PATH=/bin", "SSH_AUTH_SOCK=/agent"}
	if env := workerEnv(environ, "s", "", nil, nil); !slices.Contains(env, "SSH_AUTH_SOCK=/agent") {
		t.Fatalf("a worker with no credentials loses the agent: %v", env)
	}
	if env := workerEnv(environ, "s", "", nil, credEnvFixture()); slices.Contains(env, "SSH_AUTH_SOCK=/agent") {
		t.Fatalf("a worker with credentials keeps the agent: %v", env)
	}
}

func TestWorkerEnvStartsGitConfigFromZeroWithoutPassEnv(t *testing.T) {
	environ := []string{"PATH=/bin", "GIT_CONFIG_COUNT=1", "GIT_CONFIG_KEY_0=http.extraheader", "GIT_CONFIG_VALUE_0=token"}
	filtered := envallow.Filter(environ, envallow.Base(rules.HarnessClaudeCode))
	env := workerEnv(filtered, "s", "", nil, credEnvFixture())
	if slices.Contains(env, "GIT_CONFIG_VALUE_0=token") {
		t.Fatalf("an inherited git config value reached the worker: %v", env)
	}
	if !slices.Contains(env, "GIT_CONFIG_COUNT=4") {
		t.Fatalf("the bridge entries do not start at 0: %v", env)
	}

	kept := envallow.Filter(environ, append(envallow.Base(rules.HarnessClaudeCode), "GIT_CONFIG_*"))
	env = workerEnv(kept, "s", "", nil, credEnvFixture())
	if !slices.Contains(env, "GIT_CONFIG_VALUE_0=token") || !slices.Contains(env, "GIT_CONFIG_COUNT=5") {
		t.Fatalf("passEnv GIT_CONFIG_* does not keep the set: %v", env)
	}
}

func TestRestartFilteredExecsOnceWithFilteredEnv(t *testing.T) {
	t.Setenv("BRIDGE_ENV_SECRET", "x")
	t.Setenv("BRIDGE_ENV_KEPT", "y")
	t.Setenv("PATH", "/bin")
	rulesPath := t.TempDir() + "/rules.yaml"
	writeFile(t, rulesPath, "passEnv: [BRIDGE_ENV_KEPT]\n")
	var got []string
	old := execBinary
	execBinary = func(_ string, _, env []string) error {
		got = env

		return nil
	}
	t.Cleanup(func() { execBinary = old })
	t.Setenv(envallow.FilteredMarker, "")
	os.Unsetenv(envallow.FilteredMarker)

	if err := restartFiltered(rulesPath); err != nil {
		t.Fatal(err)
	}
	joined := strings.Join(got, "\n")
	if strings.Contains(joined, "BRIDGE_ENV_SECRET=") || !strings.Contains(joined, "BRIDGE_ENV_KEPT=y") {
		t.Fatalf("filtered env = %v", got)
	}
	if !strings.Contains(joined, envallow.FilteredMarker+"=") || !strings.Contains(joined, "BRIDGE_ENV_SECRET") || strings.Contains(joined, "SECRET=x") {
		t.Fatalf("the marker must name dropped variables and no value: %v", got)
	}

	got = nil
	t.Setenv(envallow.FilteredMarker, "A")
	if err := restartFiltered(rulesPath); err != nil || got != nil {
		t.Fatalf("a marked bridge restarted again: err=%v env=%v", err, got)
	}
	t.Cleanup(func() { envallow.SetPass(nil); startupPass = nil })
}
