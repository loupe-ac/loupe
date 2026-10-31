package codex

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/harness"
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

func TestTheCommandNamesTheCostSourceOfAnOpenRouterRun(t *testing.T) {
	fakeGit(t, "")
	for profile, want := range map[string]string{"openrouter": harness.CostSourceOpenRouter, "": ""} {
		h := newHarness(t, profile)
		spec := harness.Spec{Dir: t.TempDir(), Prompt: "- go", RunID: runID}

		for name, cmd := range map[string]harness.Command{"worker": h.Worker(spec), "resume": h.Resume(spec)} {
			if cmd.CostSource != want {
				t.Fatalf("profile %q, %s: CostSource = %q, want %q", profile, name, cmd.CostSource, want)
			}
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
