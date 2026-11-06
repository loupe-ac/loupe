package cmd

import (
	"bufio"
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

type discoveryCall struct{ project string }

// stubDiscovery replaces the API call and records the projects it started.
func stubDiscovery(t *testing.T, d api.Discovery, err error) *[]discoveryCall {
	t.Helper()
	var calls []discoveryCall
	old := startDiscovery
	t.Cleanup(func() { startDiscovery = old })
	startDiscovery = func(_ context.Context, _ *api.Client, project string) (api.Discovery, error) {
		calls = append(calls, discoveryCall{project})

		return d, err
	}

	return &calls
}

func TestInitWithAnOpenProfileStartsNothingAndSaysToRunInitAgain(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	w.gitClean = true
	calls := stubDiscovery(t, api.Discovery{}, nil)

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	last := out[strings.Index(out, "What remains:"):]
	if len(*calls) != 0 || strings.Contains(out, "Start discovery now?") {
		t.Errorf("discovery was offered or started: %v\n%s", *calls, out)
	}
	for _, want := range []string{"Finish the profile", "Run `loupe init` again when these are done. It then offers to start discovery."} {
		if !strings.Contains(last, want) {
			t.Errorf("last lines lack %q:\n%s", want, last)
		}
	}
	if strings.Contains(out, "Run discovery on the project page") {
		t.Errorf("last lines send the owner to start a report too early:\n%s", out)
	}
}

func TestInitWithUnpushedFilesStartsNothing(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	completeProfile(t, dir)
	calls := stubDiscovery(t, api.Discovery{}, nil)

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	if len(*calls) != 0 || strings.Contains(out, "Start discovery now?") {
		t.Errorf("discovery was offered or started: %v\n%s", *calls, out)
	}
	for _, want := range []string{"Commit and push .loupe.yaml", "It then offers to start discovery."} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestInitWithACompleteProfileAndAYesStartsDiscovery(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	completeProfile(t, dir)
	w.gitClean = true
	calls := stubDiscovery(t, api.Discovery{URL: "https://app.test/projects/p1"}, nil)

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	if len(*calls) != 1 || (*calls)[0].project != firstProject {
		t.Errorf("calls = %v, want one for %s", *calls, firstProject)
	}
	for _, want := range []string{"Start discovery now? It runs a worker that checks the repository and lists what remains.", "Discovery started: https://app.test/projects/p1"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if strings.Contains(out, "loupe discovery start") || strings.Contains(out, "What remains:") {
		t.Errorf("last lines name discovery after it started:\n%s", out)
	}
}

func TestInitWithACompleteProfileAndANoStartsNothing(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	completeProfile(t, dir)
	w.gitClean = true
	calls := stubDiscovery(t, api.Discovery{}, nil)

	out, err := runInitCmd(t, "1\nn\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	if len(*calls) != 0 {
		t.Errorf("calls = %v, want none", *calls)
	}
	if !strings.Contains(out, "Start discovery now?") || !strings.Contains(out, "Start discovery with `loupe discovery start` when you are ready.") {
		t.Errorf("output lacks the question or the hint:\n%s", out)
	}
}

func TestInitWithNoTerminalAsksNothingAndStartsNothing(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	completeProfile(t, dir)
	w.gitClean = true
	calls := stubDiscovery(t, api.Discovery{}, nil)
	devNull, err := os.Open(os.DevNull)
	if err != nil {
		t.Fatal(err)
	}
	defer devNull.Close()

	out, err := runInitWithIn(t, devNull, "--no-mcp", "--project", "dev")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	if len(*calls) != 0 || strings.Contains(out, "Start discovery now?") {
		t.Errorf("discovery was offered or started: %v\n%s", *calls, out)
	}
	if !strings.Contains(out, "Start discovery with `loupe discovery start` when you are ready.") {
		t.Errorf("output lacks the hint:\n%s", out)
	}
}

func TestInitReportsAFailedStartAndKeepsTheHint(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	completeProfile(t, dir)
	w.gitClean = true
	stubDiscovery(t, api.Discovery{}, errors.New("start discovery: HTTP 409: Conflict"))

	out, err := runInitCmd(t, "1\nn\ny\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}
	for _, want := range []string{"Discovery: failed, start discovery: HTTP 409: Conflict.", "Start discovery with `loupe discovery start`"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestInitOffersDiscoveryAgainAfterTheProfileAgentRan(t *testing.T) {
	remains := false
	site := api.Site{ID: "p"}
	site.DiscoveryRemains = &remains
	if wantsDiscovery(site, initLeft{}) {
		t.Error("a started run with an unchanged profile is due again")
	}
	if !wantsDiscovery(site, initLeft{profileRan: true}) {
		t.Error("a new profile does not make a new run due")
	}
}

func TestDiscoveryStartRefusesWhenTheGateFails(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	w.gitClean = true
	calls := stubDiscovery(t, api.Discovery{}, nil)

	out, err := runDiscoveryCmd(t, "--project", "dev")
	if err == nil || !strings.Contains(err.Error(), "discovery is not ready to start") || !strings.Contains(err.Error(), "Finish the profile") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if len(*calls) != 0 {
		t.Errorf("calls = %v, want none", *calls)
	}
}

// writeDefaultRules writes the rule file at the path that the gate reads.
func writeDefaultRules(t *testing.T, content string) {
	t.Helper()
	path, err := defaultRulesPath()
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	writeFile(t, path, content)
}

func TestDiscoveryStartStartsWhenTheGateHolds(t *testing.T) {
	w := newInitWorld(t, devSite())
	dir := inRepo(t, w.server.URL)
	completeProfile(t, dir)
	w.gitClean = true
	writeDefaultRules(t, "projects:\n  dev:\n    dir: "+dir+"\nwork:\n  plan:\n    prompt: go\n")
	calls := stubDiscovery(t, api.Discovery{URL: "https://app.test/projects/p2"}, nil)

	out, err := runDiscoveryCmd(t, "--project", "dev")
	if err != nil {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if len(*calls) != 1 || (*calls)[0].project != firstProject || !strings.Contains(out, "Discovery started: https://app.test/projects/p2") {
		t.Errorf("calls = %v, out = %q", *calls, out)
	}
}

// The gate never passes on accounts that it could not check.
func TestDiscoveryStartRefusesWhenTheAccountCheckCannotRun(t *testing.T) {
	for name, rules := range map[string]string{"missing": "", "unreadable": "projects: [\n"} {
		t.Run(name, func(t *testing.T) {
			w := newInitWorld(t, devSite())
			dir := inRepo(t, w.server.URL)
			completeProfile(t, dir)
			w.gitClean = true
			if rules != "" {
				writeDefaultRules(t, rules)
			}
			calls := stubDiscovery(t, api.Discovery{}, nil)

			out, err := runDiscoveryCmd(t, "--project", "dev")
			if err == nil || !strings.Contains(err.Error(), "Check the accounts:") {
				t.Fatalf("err = %v, out = %q", err, out)
			}
			if len(*calls) != 0 {
				t.Errorf("calls = %v, want none", *calls)
			}
		})
	}
}

func TestReportAccountsSaysWhyItCheckedNothing(t *testing.T) {
	path := filepath.Join(t.TempDir(), "rules.yaml")
	failing, unchecked := reportAccounts(context.Background(), io.Discard, path, api.Site{ID: "p"}, nil)
	if len(failing) != 0 || unchecked != "no rule file at "+path {
		t.Errorf("failing = %v, unchecked = %q", failing, unchecked)
	}
	blockers := discoveryBlockers(initLeft{accountCheck: unchecked}, nil)
	if len(blockers) != 1 || !strings.Contains(blockers[0], "Check the accounts: no rule file at") {
		t.Errorf("blockers = %v", blockers)
	}
}

func TestDiscoveryStartForceSkipsTheGate(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)
	calls := stubDiscovery(t, api.Discovery{}, nil)

	out, err := runDiscoveryCmd(t, "--project", "dev", "--force")
	if err != nil {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if len(*calls) != 1 || (*calls)[0].project != "dev" {
		t.Errorf("calls = %v, want one for dev", *calls)
	}
	if want := "Discovery started: " + w.server.URL + "/projects/dev"; !strings.Contains(out, want) {
		t.Errorf("out = %q, want %q", out, want)
	}
}

func TestDiscoveryStartHasOneStartCommand(t *testing.T) {
	root := newRootCmd()
	found := 0
	for _, c := range root.Commands() {
		if c.Name() != "discovery" {
			continue
		}
		for _, sub := range c.Commands() {
			if sub.Name() == "start" {
				found++
			}
		}
	}
	if found != 1 {
		t.Errorf("loupe discovery start is registered %d times", found)
	}
}

func runDiscoveryCmd(t *testing.T, args ...string) (string, error) {
	t.Helper()
	var out strings.Builder
	cmd := newRootCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetIn(bufio.NewReader(strings.NewReader("")))
	cmd.SetArgs(append([]string{"discovery", "start"}, args...))
	err := cmd.Execute()

	return out.String(), err
}
