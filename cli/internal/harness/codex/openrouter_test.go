package codex

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/transcript"
)

const openRouterProvider = `model_provider = "gw"
[model_providers.gw]
base_url = "https://openrouter.ai/api/v1"
`

func writeHomeFile(t *testing.T, home, name, body string) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(home, name), []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestConfigOpenRouter(t *testing.T) {
	for name, tc := range map[string]struct {
		profile, config string
		want            string
	}{
		"url in the profile":      {openRouterProvider, "", "gw"},
		"url in config.toml only": {`model_provider = "gw"`, "[model_providers.gw]\nbase_url = \"https://openrouter.ai/api/v1\"\n", "gw"},
		"profile overrides url":   {"model_provider = \"gw\"\n[model_providers.gw]\nbase_url = \"http://localhost:1234/v1\"\n", "[model_providers.gw]\nbase_url = \"https://openrouter.ai/api/v1\"\n", ""},
		"another host":            {"model_provider = \"gw\"\n[model_providers.gw]\nbase_url = \"https://api.example.com/v1\"\n", "", ""},
		"a look-alike host":       {"model_provider = \"gw\"\n[model_providers.gw]\nbase_url = \"https://openrouter.ai.example.com/v1\"\n", "", ""},
		"no url":                  {"model_provider = \"gw\"\n[model_providers.gw]\nenv_key = \"K\"\n", "", ""},
		"no provider":             {"[model_providers.gw]\nbase_url = \"https://openrouter.ai/api/v1\"\n", "", ""},
		"a file that is broken":   {openRouterProvider, "= broken", ""},
	} {
		t.Run(name, func(t *testing.T) {
			home := t.TempDir()
			writeHomeFile(t, home, "p.config.toml", tc.profile)
			if tc.config != "" {
				writeHomeFile(t, home, "config.toml", tc.config)
			}

			if got := configOpenRouter(home, "p"); got != tc.want {
				t.Fatalf("configOpenRouter = %q, want %q", got, tc.want)
			}
		})
	}
}

func TestAnOpenRouterRunSendsTheRunKeyAsTheSession(t *testing.T) {
	fakeGit(t, "")
	h := newHarness(t, "openrouter")
	spec := harness.Spec{Dir: t.TempDir(), Prompt: "- go", RunID: runID}
	want := []string{"-c", `model_providers.openrouter.http_headers.x-session-id="` + runID + `"`}

	for name, cmd := range map[string]harness.Command{"worker": h.Worker(spec), "resume": h.Resume(spec)} {
		if !containsRun(cmd.Args, want) {
			t.Fatalf("%s args = %v, want %v", name, cmd.Args, want)
		}
	}

	spec.RunID = ""
	if cmd := h.Worker(spec); slices.Contains(cmd.Args, want[1]) {
		t.Fatalf("a run with no key sends a header: %v", cmd.Args)
	}
}

func TestARunOnAnotherProviderSendsNoSessionHeader(t *testing.T) {
	fakeGit(t, "")
	h := newHarness(t, "")
	cmd := h.Worker(harness.Spec{Dir: t.TempDir(), Prompt: "- go", RunID: runID})

	for _, a := range cmd.Args {
		if strings.Contains(a, "http_headers") {
			t.Fatalf("args hold %q", a)
		}
	}
}

func TestReadRunNamesTheCostSourceOfAnOpenRouterRun(t *testing.T) {
	for profile, want := range map[string]string{"openrouter": harness.CostSourceOpenRouter, "": ""} {
		h := newHarness(t, profile)
		dir := runDir(t, "stdout.jsonl", `{"status":"done","summary":"x"}`)

		if got := h.ReadRun(dir, harness.RunInfo{SessionID: runID}).CostSource; got != want {
			t.Fatalf("profile %q: CostSource = %q, want %q", profile, got, want)
		}
	}
}

func TestReadRunKeepsTheTokensOfAnOpenRouterRunAndPricesNone(t *testing.T) {
	withFetched(t, map[string]transcript.FetchedPrice{"openrouter/free": {Rates: transcript.Rates{Input: 1e6, Output: 1e6}}})
	for profile, wantCost := range map[string]bool{"openrouter": false, "": true} {
		h := newHarness(t, profile)
		dir := runDir(t, "stdout.jsonl", `{"status":"done","summary":"x"}`)

		got := h.ReadRun(dir, harness.RunInfo{SessionID: runID}).Usage["openrouter/free"]
		if got.OutputTokens != 128 {
			t.Fatalf("profile %q: usage = %+v, want the token counts", profile, got)
		}
		if (got.CostUSD != nil) != wantCost {
			t.Fatalf("profile %q: cost = %v, want a cost %v", profile, got.CostUSD, wantCost)
		}
	}
}

func containsRun(args, run []string) bool {
	for i := 0; i+len(run) <= len(args); i++ {
		if slices.Equal(args[i:i+len(run)], run) {
			return true
		}
	}

	return false
}

// A run that misses a subagent leaves its usage to RunUsage, which keeps the
// tokens of an OpenRouter run and prices none. SessionUsage reads an
// interactive session, which keeps its list price.
func TestRunUsageOfAnOpenRouterRunPricesNone(t *testing.T) {
	withFetched(t, map[string]transcript.FetchedPrice{"gpt-6-astra": {Rates: transcript.Rates{Input: 1e6, Output: 1e6}}})
	since := time.Date(2026, 10, 8, 12, 15, 50, 0, time.UTC)
	for profile, wantCost := range map[string]bool{"openrouter": false, "": true} {
		home := t.TempDir()
		dir := filepath.Join(home, "sessions", "2026", "10", "08")
		if err := os.MkdirAll(dir, 0o700); err != nil {
			t.Fatal(err)
		}
		main := "rollout-2026-10-08T08-15-36-" + probeThread + ".jsonl"
		copyFile(t, filepath.Join("testdata", "probe", "sessions", "2026", "10", "08", main), filepath.Join(dir, main))
		copyFile(t, filepath.Join("testdata", "openrouter.config.toml"), filepath.Join(home, "openrouter.config.toml"))
		h := New(home, profile, filepath.Join(t.TempDir(), "threads"))
		if err := h.remember(runID, probeThread); err != nil {
			t.Fatal(err)
		}

		run, err := h.RunUsage(runID, since, time.Time{})
		if m := run["gpt-6-astra"]; err != nil || m.OutputTokens == 0 || (m.CostUSD != nil) != wantCost {
			t.Fatalf("profile %q: RunUsage = %+v, %v, want a cost %v", profile, run, err, wantCost)
		}
		session, err := h.SessionUsage(runID, since, time.Time{})
		if err != nil || session["gpt-6-astra"].CostUSD == nil {
			t.Fatalf("profile %q: SessionUsage = %+v, %v, want a cost", profile, session, err)
		}
	}
}
