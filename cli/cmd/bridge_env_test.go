package cmd

import (
	"io"
	"log/slog"
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

func TestDroppedEnvNamesWorkerLossesAfterAReload(t *testing.T) {
	t.Setenv(envallow.FilteredMarker, "GONE_AT_RESTART")
	t.Setenv("LOUPE_ROTATE_TOKEN", "x")
	t.Setenv("RELOAD_PASS_VAR", "y")
	t.Cleanup(func() { envallow.SetPass(nil) })

	envallow.SetPass([]string{"RELOAD_PASS_VAR"})
	got := droppedEnv(envallow.HarnessClaude)
	if !slices.Contains(got, "GONE_AT_RESTART") || !slices.Contains(got, "LOUPE_ROTATE_TOKEN") || slices.Contains(got, "RELOAD_PASS_VAR") {
		t.Fatalf("dropped before the reload = %v", got)
	}
	if slices.Contains(got, envallow.FilteredMarker) {
		t.Fatalf("the marker is not a dropped variable: %v", got)
	}

	envallow.SetPass(nil)
	if got = droppedEnv(envallow.HarnessClaude); !slices.Contains(got, "RELOAD_PASS_VAR") {
		t.Fatalf("a passEnv name removed by a reload must be named: %v", got)
	}
}

func TestDroppedEnvSeparatesHarnesses(t *testing.T) {
	t.Setenv("OPENAI_API_KEY", "o")
	t.Setenv("ANTHROPIC_API_KEY", "a")
	claude, codex := droppedEnv(envallow.HarnessClaude), droppedEnv(envallow.HarnessCodex)
	if !slices.Contains(claude, "OPENAI_API_KEY") || slices.Contains(claude, "ANTHROPIC_API_KEY") {
		t.Fatalf("claude-code loses = %v", claude)
	}
	if !slices.Contains(codex, "ANTHROPIC_API_KEY") || slices.Contains(codex, "OPENAI_API_KEY") {
		t.Fatalf("codex loses = %v", codex)
	}
}

func TestPendingFixesThePassListOfItsRuleSet(t *testing.T) {
	old, err := rules.Parse([]byte("passEnv: [OLD_ONLY]\nprojects:\n  loupe:\n    dir: "+t.TempDir()+"\n    work:\n      review:\n        prompt: x\n"), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	t.Setenv("OLD_ONLY", "1")
	t.Setenv("NEW_ONLY", "2")
	p := pending{set: old}
	p.apply(rules.Match{})
	t.Cleanup(func() { envallow.SetPass(nil) })

	envallow.SetPass([]string{"NEW_ONLY"})
	env := p.spec.environ()
	if !slices.Contains(env, "OLD_ONLY=1") || slices.Contains(env, "NEW_ONLY=2") {
		t.Fatalf("a worker delayed past a swap used the later list: %v", env)
	}
}

func TestHandoverEnvironRefiltersAfterAPassListChange(t *testing.T) {
	t.Setenv("PATH", "/bin")
	t.Setenv("REMOVED_LATER", "secret")
	t.Setenv("STILL_PASSED", "ok")
	t.Setenv(envallow.FilteredMarker, "EARLIER")
	t.Cleanup(func() { envallow.SetPass(nil) })

	envallow.SetPass([]string{"STILL_PASSED"})
	env := handoverEnviron()
	joined := strings.Join(env, "\n")
	if strings.Contains(joined, "REMOVED_LATER=") || !strings.Contains(joined, "STILL_PASSED=ok") {
		t.Fatalf("handover env = %v", env)
	}
	if !strings.Contains(joined, envallow.FilteredMarker+"=EARLIER,") || !strings.Contains(joined, "REMOVED_LATER") {
		t.Fatalf("the marker must keep and extend the dropped names: %v", env)
	}
}

func TestUnsetRemovedClearsOnlyARemovedPassName(t *testing.T) {
	t.Setenv("REMOVED_LATER", "secret")
	t.Setenv("STILL_PASSED", "ok")
	t.Setenv("BRIDGE_ONLY", "keep")
	t.Cleanup(func() { envallow.SetPass(nil) })

	previous := []string{"REMOVED_LATER", "STILL_PASSED"}
	envallow.SetPass([]string{"STILL_PASSED"})
	unsetRemoved(slog.New(slog.NewTextHandler(io.Discard, nil)), previous)

	if _, ok := os.LookupEnv("REMOVED_LATER"); ok {
		t.Fatal("a removed passEnv name stayed in the bridge environment")
	}
	if os.Getenv("STILL_PASSED") != "ok" || os.Getenv("BRIDGE_ONLY") != "keep" {
		t.Fatal("a kept name was unset")
	}
}
