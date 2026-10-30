package rules

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/directive"
	"github.com/loupe-ac/loupe/cli/internal/event"
)

const workRequestID = "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90"

const workFile = claudeAccount + claudeDefaults + `
projects:
  loupe:
    dir: {dir}
launch:
  command: [osascript, -e, 'run {script}']
workerPools:
  quick:
    size: 1
experiments:
  split-trial:
    covers: [split]
    baseline: a
    variants:
      - {name: a, weight: 1, model: opus}
      - {name: b, weight: 3, model: sonnet}
    metrics: [cost, merge-rate]
work:
  implement:
    prompt: Implement {cardNumber} {cardId} {project} {projectId} {kind} {ruleId} {workRequestId}.
    workerPool: quick
    permissions: read-only
    before:
      run: [prep, '{cardNumber}', '{workRequestId}']
  pair:
    action: interactive
    prompt: Pair on {cardNumber} in {project}.
    model: opus
  test:
    action: command
    run: [make, test, 'CARD={cardNumber}', '{kind}']
    timeout: 5m
  split:
    prompt: Split {cardNumber}.
`

func workRequest(kind string) api.WorkRequest {
	return api.WorkRequest{
		Type:          event.WorkRequestType,
		ProjectID:     projectID,
		Subject:       api.WorkRequestSubject{Type: "work-request", ID: workRequestID},
		WorkRequestID: workRequestID,
		Kind:          kind,
		State:         api.WorkRequestOpen,
		SubjectType:   api.SubjectCard,
		SubjectID:     cardID,
		CardNumber:    87,
		RuleID:        "impl.rule",
		CreatedAt:     time.Date(2026, 10, 1, 12, 30, 0, 0, time.UTC),
	}
}

func TestParseRefusesAnInvalidWorkEntry(t *testing.T) {
	entry := func(kind, fields string) string {
		return "projects:\n  loupe:\n    dir: {dir}\nlaunch:\n  command: ['{script}']\nworkerPools:\n  quick: {size: 1}\n" +
			"work:\n  " + kind + ":\n    " + strings.ReplaceAll(strings.TrimSpace(fields), "\n", "\n    ") + "\n"
	}
	interactive := "action: interactive\nprompt: x\n"
	command := "action: command\nrun: [make]\n"
	variants := "variants:\n  - {name: a, weight: 1, model: opus}\n"
	exp := func(fields string) string {
		return entry("x", "prompt: x") + "experiments:\n  trial:\n    covers: [x]\n    baseline: a\n    " + strings.ReplaceAll(strings.TrimSpace(fields), "\n", "\n    ") + "\n"
	}

	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"kind with a capital":          {entry("Implement", "prompt: x"), `work "Implement": a kind is 1 to 40 lowercase letters`},
		"kind with a digit first":      {entry("1st", "prompt: x"), `work "1st": a kind is`},
		"kind too long":                {entry(strings.Repeat("a", 41), "prompt: x"), "a kind is"},
		"unknown action":               {entry("x", "action: launch\nprompt: x"), `work "x": action "launch" is not interactive or command`},
		"unknown field":                {entry("x", "prompt: x\nmaxChain: 2"), "field maxChain not found"},
		"allowUntrusted":               {entry("x", "prompt: x\nallowUntrusted: true"), "field allowUntrusted not found"},
		"worker without prompt":        {entry("x", "model: opus"), `work "x": prompt is required`},
		"worker with a blank prompt":   {entry("x", "prompt: '  '"), "prompt is required"},
		"interactive without prompt":   {entry("x", "action: interactive"), "prompt is required"},
		"worker with run":              {entry("x", "prompt: x\nrun: [make]"), "run belongs to action command, and this entry runs a worker"},
		"worker with timeout":          {entry("x", "prompt: x\ntimeout: 1m"), "timeout belongs to action command"},
		"interactive with before":      {entry("x", interactive+"before:\n  run: [x]"), "before names worker behaviour, and action interactive launches no worker"},
		"interactive with variants":    {entry("x", interactive+variants), "work entry x sets variants; move the variants"},
		"interactive with workerPool":  {entry("x", interactive+"workerPool: quick"), "workerPool names worker behaviour"},
		"interactive with metrics":     {entry("x", interactive+"metrics: [cost]"), "work entry x sets metrics; move the variants"},
		"interactive with run":         {entry("x", interactive+"run: [x]"), "run belongs to action command, and this entry launches an interactive session"},
		"command with prompt":          {entry("x", command+"prompt: x"), "prompt names agent behaviour, and action command starts no agent"},
		"command with model":           {entry("x", command+"model: opus"), "model names agent behaviour"},
		"command with permissions":     {entry("x", command+"permissions: full"), "permissions names agent behaviour"},
		"command with account":         {entry("x", command+"account: claude"), "account names agent behaviour"},
		"command with before":          {entry("x", command+"before:\n  run: [x]"), "before names agent behaviour"},
		"command with variants":        {entry("x", command+variants), "work entry x sets variants; move the variants"},
		"command with workerPool":      {entry("x", command+"workerPool: quick"), "workerPool names agent behaviour"},
		"command with metrics":         {entry("x", command+"metrics: [cost]"), "work entry x sets metrics; move the variants"},
		"bad metric key":               {exp("metrics: [Cost]\n" + variants), `metric "Cost": a metric key is 1 to 128`},
		"metric key too long":          {exp("metrics: [c" + strings.Repeat("o", 128) + "]\n" + variants), "a metric key is 1 to 128"},
		"repeated metric":              {exp("metrics: [cost, cost]\n" + variants), `metric "cost" is listed twice`},
		"interactive with headline":    {entry("x", interactive+"headline:\n  guards: [cost]"), "work entry x sets headline; move the variants"},
		"command with headline":        {entry("x", command+"headline:\n  guards: [cost]"), "work entry x sets headline; move the variants"},
		"empty headline":               {exp("headline: {}\n" + variants), "headline needs a goal, guards or controls"},
		"goal without metric":          {exp("headline:\n  goal: {better: lower}\n" + variants), "headline goal: metric \"\": a metric key is 1 to 128"},
		"goal with bad better":         {exp("headline:\n  goal: {metric: cost, better: up}\n" + variants), `headline goal: better is lower or higher, got "up"`},
		"goal with bad metric":         {exp("headline:\n  goal: {metric: Cost, better: lower}\n" + variants), `headline goal: metric "Cost": a metric key is 1 to 128`},
		"bad guard key":                {exp("headline:\n  guards: [Cost]\n" + variants), `headline guard "Cost": a metric key is 1 to 128`},
		"repeated guard":               {exp("headline:\n  guards: [cost, cost]\n" + variants), `headline guard "cost" is listed twice`},
		"goal metric is also a guard":  {exp("headline:\n  goal: {metric: cost, better: lower}\n  guards: [cost]\n" + variants), `headline guard "cost" is also the goal metric`},
		"control without share":        {exp("headline:\n  controls:\n    - {metric: harness-events:diagnostics, variant: a, expect: \"> 0\"}\n" + variants), `headline control "harness-events:diagnostics" on variant "a": share is required`},
		"control share above 1":        {exp("headline:\n  controls:\n    - {metric: cost, variant: a, expect: \"> 0\", share: 1.5}\n" + variants), "share must be above 0 and at most 1, got 1.5"},
		"control share of 0":           {exp("headline:\n  controls:\n    - {metric: cost, variant: a, expect: \"> 0\", share: 0}\n" + variants), "share must be above 0 and at most 1, got 0"},
		"control on bad variant":       {exp("headline:\n  controls:\n    - {metric: cost, variant: zzz, expect: \"> 0\", share: 0.5}\n" + variants), `the experiment declares no variant "zzz"`},
		"control with bad expect":      {exp("headline:\n  controls:\n    - {metric: cost, variant: a, expect: \"about 3\", share: 0.5}\n" + variants), `expect "about 3" is a comparison and a number`},
		"control with bad metric":      {exp("headline:\n  controls:\n    - {metric: Cost, variant: a, expect: \"> 0\", share: 0.5}\n" + variants), `headline control "Cost" on variant "a": a metric key is 1 to 128`},
		"repeated control":             {exp("headline:\n  controls:\n    - {metric: cost, variant: a, expect: \"> 0\", share: 0.5}\n    - {metric: cost, variant: a, expect: \"> 1\", share: 0.5}\n" + variants), `headline control "cost" on variant "a" is listed twice`},
		"unknown headline field":       {exp("headline:\n  guard: [cost]\n" + variants), "field guard not found"},
		"command without run":          {entry("x", "action: command"), "run is required"},
		"command timeout too long":     {entry("x", command+"timeout: 2h"), "the most it takes is 1h0m0s"},
		"command timeout not duration": {entry("x", command+"timeout: soon"), `timeout "soon" is not a duration`},
		"unknown placeholder":          {entry("x", "prompt: 'Card {title}'"), `work "x": unknown placeholder {title}`},
		"an old event placeholder":     {entry("x", "prompt: 'Moved to {to}'"), "unknown placeholder {to}"},
		"unknown run placeholder":      {entry("x", "action: command\nrun: [make, '{title}']"), "run: unknown placeholder {title}"},
		"unknown before placeholder":   {entry("x", "prompt: x\nbefore:\n  run: [prep, '{title}']"), "before.run: unknown placeholder {title}"},
		"before without run":           {entry("x", "prompt: x\nbefore:\n  timeout: 1m"), "before.run is required"},
		"model with a space":           {entry("x", "prompt: x\nmodel: 'claude opus'"), `model "claude opus" holds whitespace`},
		"empty variants":               {exp("variants: []"), "it has no variants"},
		"variant weight":               {exp("variants:\n  - {name: a, weight: 0, model: opus}"), `variant "a": weight must be at least 1`},
		"variant name":                 {exp("variants:\n  - {name: A, weight: 1, model: opus}"), "a name is 1 to 64"},
		"variant model too long":       {exp("variants:\n  - {name: a, weight: 1, model: " + strings.Repeat("m", 101) + "}"), "the server takes at most 100"},
		"two variants of one name":     {exp("variants:\n  - {name: a, weight: 1, model: opus}\n  - {name: a, weight: 1, model: sonnet}"), `two variants are named "a"`},
		"undeclared pool":              {entry("x", "prompt: x\nworkerPool: slow"), `work "x": workerPool "slow" is not in workerPools, which declares default, quick`},
		"subject with a capital":       {entry("x", "subject: Analysis\nprompt: x"), `work "x": subject "Analysis" is not 1 to 32`},
		"subject too long":             {entry("x", "subject: "+strings.Repeat("a", 33)+"\nprompt: x"), "is not 1 to 32"},
		"cardId off card":              {entry("x", "subject: analysis\nprompt: 'Do {cardId}'"), "prompt uses {cardId}, which names a card, and this entry runs subject analysis"},
		"cardNumber in run off card":   {entry("x", "subject: analysis\naction: command\nrun: [make, '{cardNumber}']"), "run uses {cardNumber}, which names a card"},
		"cardId in before off card":    {entry("x", "subject: analysis\nprompt: x\nbefore:\n  run: [prep, '{cardId}']"), "before.run uses {cardId}, which names a card"},
		"param without pattern":        {entry("x", command+"params:\n  out: {description: d}"), "params.out: pattern is required"},
		"param bad pattern":            {entry("x", command+"params:\n  out: {pattern: '(?!x)'}"), "params.out: pattern does not compile as RE2"},
		"param built-in name":          {entry("x", command+"params:\n  kind: {pattern: a}"), `"kind" is a built-in placeholder`},
		"param bad name":               {entry("x", command+"params:\n  out1: {pattern: a}"), `name "out1" is not letters only`},
		"undeclared param placeholder": {entry("x", "action: command\nrun: [make, '{out}']"), "run: unknown placeholder {out}"},
		"unknown param field":          {entry("x", command+"params:\n  out: {pattern: a, flag: b}"), "field flag not found"},
		"worker with params":           {entry("x", "prompt: x\nparams:\n  out: {pattern: a}"), "params belongs to action command"},
		"worker with description":      {entry("x", "prompt: x\ndescription: d"), "description belongs to action command"},
		"interactive with refresh":     {entry("x", interactive+"refresh:\n  run: [x]"), "refresh names worker behaviour, and action interactive launches no worker"},
		"interactive with reset":       {entry("x", interactive+"reset:\n  run: [x]"), "reset names worker behaviour, and action interactive launches no worker"},
		"command with refresh":         {entry("x", command+"refresh:\n  run: [x]"), "refresh names agent behaviour"},
		"command with reset":           {entry("x", command+"reset:\n  run: [x]"), "reset names agent behaviour"},
		"unknown refresh placeholder":  {entry("x", "prompt: x\nrefresh:\n  run: [up, '{title}']"), "refresh.run: unknown placeholder {title}"},
		"refresh without run":          {entry("x", "prompt: x\nrefresh:\n  timeout: 1m"), "refresh.run is required"},
		"reset timeout too long":       {entry("x", "prompt: x\nreset:\n  run: [down]\n  timeout: 2h"), "reset.timeout is 2h, and the most it takes is 1h0m0s"},
		"cardId in reset off card":     {entry("x", "subject: analysis\nprompt: x\nreset:\n  run: [down, '{cardId}']"), "reset.run uses {cardId}, which names a card"},
		"interactive with params":      {entry("x", interactive+"params:\n  out: {pattern: a}"), "params belongs to action command"},
		"session not interactive":      {entry("session", "prompt: x"), "the session entry needs action interactive"},
		"session as a command":         {entry("session", command), "the session entry needs action interactive"},
		"interactive off card":         {entry("x", "subject: analysis\n"+interactive), "action interactive opens a session on a card, and this entry runs subject analysis"},
	} {
		t.Run(name, func(t *testing.T) {
			text, _ := file(t, tc.body)
			_, err := Parse([]byte(text), Defaults{})
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.want)
			}
		})
	}
}

func TestParseAcceptsEmptyMetricsWithVariants(t *testing.T) {
	body := "projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    prompt: x\n" +
		"experiments:\n  trial:\n    covers: [x]\n    baseline: a\n    metrics: []\n    variants:\n      - {name: a, weight: 1, model: opus}\n"
	text, _ := file(t, body)
	if _, err := Parse([]byte(text), Defaults{}); err != nil {
		t.Fatalf("err = %v", err)
	}
}

func TestParseRefusesTooManyMetrics(t *testing.T) {
	keys := make([]string, MaxMetrics+1)
	for i := range keys {
		keys[i] = "m" + strings.Repeat("a", i+1)
	}
	body := "projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    prompt: x\n" +
		"experiments:\n  trial:\n    covers: [x]\n    baseline: a\n    metrics: [" + strings.Join(keys, ", ") +
		"]\n    variants:\n      - {name: a, weight: 1, model: opus}\n"
	text, _ := file(t, body)
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "it has 17 metrics, and the server takes at most 16") {
		t.Fatalf("err = %v", err)
	}
}

func TestParseRefusesTooManyVariants(t *testing.T) {
	var b strings.Builder
	b.WriteString("projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    prompt: x\nexperiments:\n  trial:\n    covers: [x]\n    baseline: v\n    variants:\n")
	for i := range MaxVariants + 1 {
		b.WriteString("      - {name: v" + strings.Repeat("a", i+1) + ", weight: 1, model: opus}\n")
	}
	text, _ := file(t, b.String())
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "it has 33 variants") {
		t.Fatalf("err = %v", err)
	}
}

func TestParseRefusesAnInteractiveEntryWithNoLaunch(t *testing.T) {
	text, _ := file(t, "projects:\n  loupe:\n    dir: {dir}\nwork:\n  pair:\n    action: interactive\n    prompt: x\n")
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "launch.command is required") {
		t.Fatalf("err = %v", err)
	}
}

func TestParseRefusesAnInteractiveEntryOnWindows(t *testing.T) {
	old := goos
	goos = "windows"
	t.Cleanup(func() { goos = old })
	text, _ := file(t, "projects:\n  loupe:\n    dir: {dir}\nlaunch:\n  command: ['{script}']\nwork:\n  pair:\n    action: interactive\n    prompt: x\n")
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "needs a POSIX shell") {
		t.Fatalf("err = %v", err)
	}
}

func TestParseRefusesAnEmptyDefaultPoolForAWorkerEntry(t *testing.T) {
	text, _ := file(t, "projects:\n  loupe:\n    dir: {dir}\nmaxWorkers: 1\nworkerPools:\n  quick: {size: 1}\nwork:\n  x:\n    prompt: x\n")
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), `work "x": the default pool has no slot`) {
		t.Fatalf("err = %v", err)
	}
}

func TestMatchWorkRunsAWorker(t *testing.T) {
	text, dir := file(t, workFile)
	s, err := Parse([]byte(text), Defaults{PermissionMode: "acceptEdits", Model: "sonnet"})
	if err != nil {
		t.Fatal(err)
	}
	checkLoupe(t, s)

	m := s.MatchWork(workRequest("implement"))
	if m.Skip != Run || m.Rule != "work:implement" || m.Action != "" || m.Project != "loupe" || m.Dir != dir || m.Pool != "quick" ||
		m.PermissionMode != "plan" || m.Model != "sonnet" || m.Account != "claude" || m.Harness != HarnessClaudeCode ||
		m.Schema == "" || m.Experiment != nil || m.Command != nil {
		t.Fatalf("match = %+v", m)
	}
	want := directive.Render("Implement 87 "+cardID+" loupe "+projectID+" implement impl.rule "+workRequestID+".", nil)
	if m.Prompt != want {
		t.Fatalf("prompt = %q, want %q", m.Prompt, want)
	}
	if m.Before == nil || !slices.Equal(m.Before.Argv, []string{"prep", "87", workRequestID}) || m.Before.Timeout != DefaultBeforeTimeout {
		t.Fatalf("before = %+v", m.Before)
	}
}

func TestMatchWorkLaunchesAnInteractiveSession(t *testing.T) {
	s := checked(t, workFile)

	m := s.MatchWork(workRequest("pair"))
	if m.Skip != Run || m.Rule != "work:pair" || m.Action != ActionInteractive || m.Pool != "" || m.Model != "opus" || m.Schema != "" ||
		m.Prompt != "Pair on 87 in loupe." || m.Before != nil || m.Command != nil {
		t.Fatalf("match = %+v", m)
	}
}

func TestMatchWorkRunsACommand(t *testing.T) {
	s := checked(t, workFile)

	m := s.MatchWork(workRequest("test"))
	if m.Skip != Run || m.Rule != "work:test" || m.Action != ActionCommand || m.Pool != "" || m.Prompt != "" || m.Schema != "" || m.Command == nil {
		t.Fatalf("match = %+v", m)
	}
	if !slices.Equal(m.Command.Argv, []string{"make", "test", "CARD=87", "test"}) || m.Command.Timeout != 5*time.Minute {
		t.Fatalf("command = %+v", m.Command)
	}
}

// A teardown is a command entry that the app requests when a card reaches a
// terminal column. Under worktree isolation the bridge removes its own worktree
// of the card after the command.
func TestMatchWorkRunsATeardownCommand(t *testing.T) {
	path, err := config.WorktreeDir("loupe", 87)
	if err != nil {
		t.Fatal(err)
	}
	s := checked(t, workFile+`
  teardown:
    action: command
    run: [just, worktree-down, 'card-{cardNumber}']
`)

	m := s.MatchWork(workRequest("teardown"))
	if m.Skip != Run || m.Rule != "work:teardown" || m.Action != ActionCommand || m.Prompt != "" || m.Command == nil {
		t.Fatalf("match = %+v", m)
	}
	want := []string{"sh", "-c", teardownAfterScript, "sh", path, "just", "worktree-down", "card-87"}
	if !slices.Equal(m.Command.Argv, want) || m.Command.Timeout != DefaultCommandTimeout {
		t.Fatalf("command = %+v", m.Command)
	}
}

func TestMatchWorkCarriesTheVariantsAsAnExperiment(t *testing.T) {
	text, _ := file(t, workFile)
	s, err := Parse([]byte(text), Defaults{Model: "haiku"})
	if err != nil {
		t.Fatal(err)
	}
	checkLoupe(t, s)

	m := s.MatchWork(workRequest("split"))
	if m.Skip != Run || m.Model != "" || m.Pool != DefaultPool || m.Experiment == nil || m.Experiment.Name != "split-trial" || !slices.Equal(m.Experiment.Covers, []string{"split"}) {
		t.Fatalf("match = %+v", m)
	}
	if got := m.ApplyVariant(m.Experiment.Variants[1]); got.Model != "sonnet" || got.Account != "claude" || got.PermissionMode != "" {
		t.Fatalf("ApplyVariant = %+v", got)
	}
	want := []Variant{{Name: "a", Weight: 1, Model: "opus"}, {Name: "b", Weight: 3, Model: "sonnet"}}
	if !reflect.DeepEqual(m.Experiment.Variants, want) {
		t.Fatalf("variants = %+v", m.Experiment.Variants)
	}
	if !slices.Equal(m.Experiment.Metrics, []string{"cost", "merge-rate"}) {
		t.Fatalf("metrics = %v", m.Experiment.Metrics)
	}
	m.Experiment.Variants[0].Model = "changed"
	m.Experiment.Metrics[0] = "changed"
	again := s.MatchWork(workRequest("split")).Experiment
	if again.Variants[0].Model != "opus" || again.Metrics[0] != "cost" {
		t.Fatal("a caller changed the set's experiment")
	}
}

func TestMatchWorkTakesTheModelAndTheEffortOfTheRequest(t *testing.T) {
	s := checked(t, workFile)

	for _, kind := range []string{"implement", "pair"} {
		w := workRequest(kind)
		w.Model, w.Effort = "claude-opus-4-1", "xhigh"
		if m := s.MatchWork(w); m.Skip != Run || m.Model != "claude-opus-4-1" || m.Effort != "xhigh" {
			t.Fatalf("%s: match = %+v", kind, m)
		}
	}
	if m := s.MatchWork(workRequest("pair")); m.Model != "opus" || m.Effort != "" {
		t.Fatalf("no request model: match = %+v", m)
	}
}

// A model the request names is no draw, so the run joins no experiment.
func TestARequestModelRunsOutsideTheExperiment(t *testing.T) {
	s := checked(t, workFile)
	w := workRequest("split")
	w.Model = "haiku"

	if m := s.MatchWork(w); m.Skip != Run || m.Model != "haiku" || m.Experiment != nil {
		t.Fatalf("match = %+v", m)
	}
	if m := s.MatchWork(workRequest("split")); m.Experiment == nil || m.Model != "" {
		t.Fatalf("no request model: match = %+v", m)
	}
}

func TestMatchWorkSkipsWhatItCannotRun(t *testing.T) {
	s := checked(t, workFile)
	other := workRequest("implement")
	other.ProjectID = "0192f3a1-4b2c-7d3e-8f10-ffffffffffff"

	if m := s.MatchWork(workRequest("deploy")); m.Skip != NoRule || m.Project != "loupe" {
		t.Fatalf("unknown kind: match = %+v", m)
	}
	if m := s.MatchWork(other); m.Skip != Unmapped {
		t.Fatalf("unknown project: match = %+v", m)
	}
	if m := parse(t, workFile).MatchWork(workRequest("implement")); m.Skip != Unmapped {
		t.Fatalf("unchecked set: match = %+v", m)
	}
}

func TestAGoneProjectKillsItsWork(t *testing.T) {
	s := checked(t, workFile)

	if !s.HasWork() || s.WorkDead("loupe") != "" {
		t.Fatalf("has work = %v, dead = %q before the kill", s.HasWork(), s.WorkDead("loupe"))
	}
	if !s.KillProjectWork("loupe", api.ReasonProjectGone) || s.KillProjectWork("loupe", api.ReasonProjectGone) {
		t.Fatal("KillProjectWork reports a death once")
	}
	if m := s.MatchWork(workRequest("implement")); m.Skip != NoRule {
		t.Fatalf("match = %+v", m)
	}
	if got := s.WorkDead("loupe"); got != api.ReasonProjectGone {
		t.Fatalf("dead = %q", got)
	}
}

// An entry runs the subject type it names, card by default, and skips a
// request about another subject.
func TestMatchWorkRunsTheSubjectOfTheEntry(t *testing.T) {
	s := checked(t, claudeAccount+claudeDefaults+"projects:\n  loupe:\n    dir: {dir}\nwork:\n  implement:\n    prompt: Implement {cardNumber}.\n"+
		"  analyse:\n    subject: analysis\n    action: command\n    run: [analyse, '{subjectType}', '{subjectId}', '{project}']\n")
	analysis := workRequest("analyse")
	analysis.SubjectType, analysis.SubjectID, analysis.CardNumber = "analysis", "0199a0e2-aaaa-7c5e-9f2a-3b1c6d7e8f90", 0

	m := s.MatchWork(analysis)
	if m.Skip != Run || m.Command == nil || !slices.Equal(m.Command.Argv, []string{"analyse", "analysis", "0199a0e2-aaaa-7c5e-9f2a-3b1c6d7e8f90", "loupe"}) {
		t.Fatalf("match = %+v", m)
	}
	onCard := workRequest("analyse")
	if m := s.MatchWork(onCard); m.Skip != NoRule {
		t.Fatalf("a card request matched the analysis entry: %+v", m)
	}
	analysis.Kind = "implement"
	if m := s.MatchWork(analysis); m.Skip != NoRule {
		t.Fatalf("an analysis request matched the card entry: %+v", m)
	}
}

// A card subject fills the card placeholders and the subject ones alike.
func TestMatchWorkFillsTheSubjectOfACard(t *testing.T) {
	s := checked(t, claudeAccount+claudeDefaults+"projects:\n  loupe:\n    dir: {dir}\nwork:\n  implement:\n    prompt: '{cardId} {cardNumber} {subjectType} {subjectId}'\n")
	m := s.MatchWork(workRequest("implement"))
	if want := cardID + " 87 card " + cardID; !strings.HasPrefix(m.Prompt, want) {
		t.Fatalf("prompt = %q, want it to start with %q", m.Prompt, want)
	}
}

// A request that starts from an inbox action appends the note to the prompt of
// an interactive session. A request with no action appends nothing.
func TestMatchWorkAppendsTheInboxNote(t *testing.T) {
	s := checked(t, workFile)
	w := workRequest("pair")
	plain := s.MatchWork(w)
	w.Context.InboxActionID = "01a10beb-ba65-736b-8626-a6e3fa59dfc5"
	w.Context.InboxItemNumber = 12
	w.Context.InboxItemID = "01a137da-4de8-70ae-991f-2ef19584f2a6"
	m := s.MatchWork(w)
	if want := plain.Prompt + "\n\n" + directive.InboxNote(12, w.Context.InboxItemID); m.Skip != Run || m.Prompt != want {
		t.Fatalf("prompt = %q, want %q", m.Prompt, want)
	}

	// A note with no item id sends the agent to a call that inbox_get refuses,
	// so the bridge appends none.
	w.Context.InboxItemID = ""
	if m := s.MatchWork(w); m.Prompt != plain.Prompt {
		t.Fatalf("prompt with no item id = %q, want %q", m.Prompt, plain.Prompt)
	}
}

func TestCapabilities(t *testing.T) {
	for name, tc := range map[string]struct {
		body string
		want []string
	}{
		"workers only":     {claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    prompt: x\n", []string{"work-requests"}},
		"with interactive": {workFile, []string{"work-requests", "interactive"}},
		"with session":     {workFile + "  session:\n    action: interactive\n    prompt: x\n", []string{"work-requests", "interactive", "session"}},
		"session only":     {claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nlaunch:\n  command: ['{script}']\nwork:\n  session:\n    action: interactive\n    prompt: x\n", []string{"work-requests", "interactive", "session"}},
		"app prompts only": {claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nappPrompts: true\n", []string{"work-requests", "app-prompts"}},
		"app prompts with subjects": {claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nappPrompts: true\nwork:\n  y:\n    subject: analysis\n    prompt: x\n",
			[]string{"work-requests", "subject-analysis", "app-prompts"}},
		"with subjects": {claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    subject: review\n    prompt: x\n" +
			"  y:\n    subject: analysis\n    prompt: x\n  z:\n    subject: analysis\n    prompt: x\n  w:\n    subject: card\n    prompt: x\n",
			[]string{"work-requests", "subject-analysis", "subject-review"}},
	} {
		t.Run(name, func(t *testing.T) {
			if got := parse(t, tc.body).Capabilities(); !slices.Equal(got, tc.want) {
				t.Fatalf("Capabilities = %v, want %v", got, tc.want)
			}
		})
	}
}

func TestWorkKindsOn(t *testing.T) {
	two := claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nwork:\n  fix:\n    prompt: x\n  tech-design:\n    prompt: x\n"
	command := "  test:\n    action: command\n    run: [make]\n"
	for name, tc := range map[string]struct {
		body     string
		problems map[string]string
		want     []string
	}{
		"kinds in name order":                         {two, nil, []string{"fix", "teardown", "tech-design"}},
		"isolation none names no teardown":            {strings.Replace(two, "account: claude\n", "account: claude\n  isolation: none\n", 1), nil, []string{"fix", "tech-design"}},
		"a teardown entry replaces the built-in kind": {two + "  teardown:\n    action: command\n    run: [make]\n", nil, []string{"fix", "teardown", "tech-design"}},
		"app prompts only":                            {claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nappPrompts: true\n", nil, []string{"teardown"}},
		"agents off keeps command entries":            {"projects:\n  loupe:\n    dir: {dir}\nwork:\n  fix:\n    prompt: x\n" + command, nil, []string{"teardown", "test"}},
		"failed account keeps command entries":        {two + command, map[string]string{"claude": "not logged in"}, []string{"teardown", "test"}},
		"other failed account keeps every kind":       {two + command, map[string]string{"other": "not logged in"}, []string{"fix", "teardown", "tech-design", "test"}},
		"other subjects name their subject":           {two + "  implement:\n    subject: analysis\n    prompt: x\n", nil, []string{"fix", "analysis:implement", "teardown", "tech-design"}},
	} {
		t.Run(name, func(t *testing.T) {
			s := parse(t, tc.body)
			s.SetAccountProblems(tc.problems)
			got := s.WorkKindsOn()
			if got == nil || !slices.Equal(got, tc.want) {
				t.Fatalf("WorkKindsOn = %#v, want %#v", got, tc.want)
			}
		})
	}
}

// A project rename stops the work of the old slug, and any other event stops
// nothing.
func TestARenamedProjectKillsItsWork(t *testing.T) {
	s := checked(t, workFile)
	renamed := event.Event{Type: event.ProjectRenamedType, ProjectID: projectID, FromSlug: "loupe", ToSlug: "loupe-2"}

	if slug, reason := s.KillWork(event.Event{Type: event.CardHeldType, ProjectID: projectID}); slug != "" || reason != "" {
		t.Fatalf("a hold killed %q for %q", slug, reason)
	}
	if slug, reason := s.KillWork(renamed); slug != "loupe" || reason != api.ReasonProjectRenamed {
		t.Fatalf("KillWork = %q, %q", slug, reason)
	}
	if m := s.MatchWork(workRequest("implement")); m.Skip != NoRule {
		t.Fatalf("match = %+v", m)
	}
	if slug, _ := s.KillWork(renamed); slug != "" {
		t.Fatal("a second rename killed the work again")
	}
}

// checkLoupe resolves the loupe project of a set parsed with its own defaults.
func checkLoupe(t *testing.T, s *Set) {
	t.Helper()
	if err := s.Check(t.Context(), columnsServer(t, map[string]string{"loupe": loupeColumns})); err != nil {
		t.Fatal(err)
	}
}

const contextFile = claudeAccount + claudeDefaults + `
projects:
  loupe:
    dir: {dir}
work:
  fix:
    prompt: Fix pull request {pullRequestNumber} at {pullRequestUrl} on {headSha} for {reason}.
    before:
      run: [prep, '{cardNumber}', '{cardId}', '{pullRequestNumber}']
  revise:
    prompt: Revise document {documentId}.
  sync:
    action: command
    run: [sync, '{pullRequestNumber}', '{headSha}', '{reason}', '{documentId}', '{pullRequestUrl}']
`

func TestMatchWorkFillsTheContext(t *testing.T) {
	s := checked(t, contextFile)
	w := workRequest("fix")
	w.Context = api.WorkRequestContext{
		PullRequestNumber: 42, PullRequestURL: "https://github.com/acme/widgets/pull/42", HeadSHA: "abc1234",
		Reason: "checks-failed", DocumentID: "01a10beb-ba65-736b-8626-a6e3fa59dfc5",
	}

	m := s.MatchWork(w)
	want := directive.Render("Fix pull request 42 at https://github.com/acme/widgets/pull/42 on abc1234 for checks-failed.", nil)
	if m.Skip != Run || m.Prompt != want {
		t.Fatalf("prompt = %q, want %q", m.Prompt, want)
	}
	if m.Before == nil || !slices.Equal(m.Before.Argv, []string{"prep", "87", cardID, "42"}) {
		t.Fatalf("before = %+v", m.Before)
	}

	w.Kind = "revise"
	if m := s.MatchWork(w); m.Prompt != directive.Render("Revise document 01a10beb-ba65-736b-8626-a6e3fa59dfc5.", nil) {
		t.Fatalf("prompt = %q", m.Prompt)
	}

	w.Kind = "sync"
	m = s.MatchWork(w)
	if m.Command == nil || !slices.Equal(m.Command.Argv, []string{
		"sync", "42", "abc1234", "checks-failed", "01a10beb-ba65-736b-8626-a6e3fa59dfc5", "https://github.com/acme/widgets/pull/42",
	}) {
		t.Fatalf("command = %+v", m.Command)
	}
}

// A request with no context, as an older server sends it, fills each context
// placeholder as empty, so bridge-before.sh reads no pull request.
func TestMatchWorkFillsAnAbsentContextAsEmpty(t *testing.T) {
	s := checked(t, contextFile)

	m := s.MatchWork(workRequest("fix"))
	if m.Skip != Run || m.Prompt != directive.Render("Fix pull request  at  on  for .", nil) {
		t.Fatalf("prompt = %q", m.Prompt)
	}
	if m.Before == nil || !slices.Equal(m.Before.Argv, []string{"prep", "87", cardID, ""}) {
		t.Fatalf("before = %+v", m.Before)
	}

	w := workRequest("sync")
	m = s.MatchWork(w)
	if m.Command == nil || !slices.Equal(m.Command.Argv, []string{"sync", "", "", "", "", ""}) {
		t.Fatalf("command = %+v", m.Command)
	}
}

// An empty context value is a real state, such as a card with no pull
// request, so a rerun never lacks one.
func TestWorkGapsLeavesOutTheContextPlaceholders(t *testing.T) {
	s := checked(t, contextFile)

	if gaps := s.WorkGaps(workRequest("sync")); gaps != nil {
		t.Fatalf("gaps = %v", gaps)
	}
	w := workRequest("sync")
	w.WorkRequestID = ""
	if gaps := s.WorkGaps(w); gaps != nil {
		t.Fatalf("gaps = %v, want none, because the sync command names no work request", gaps)
	}
}

// A bridge that opts in runs the app prompt of a kind its work map does not
// hold, with the defaults of the file and the flags. A local entry wins.
func TestMatchWorkRunsTheAppPromptOfAnUnmappedKind(t *testing.T) {
	withPrompt := func(kind, prompt string) api.WorkRequest {
		w := workRequest(kind)
		w.Prompt = prompt
		w.Context.Reason = "conflict"

		return w
	}
	for name, tc := range map[string]struct {
		body   string
		w      api.WorkRequest
		skip   Skip
		prompt string
	}{
		"an unmapped kind runs the prompt": {"appPrompts: true\n" + workFile, withPrompt("review", "Review {cardNumber} in {project} for {reason}."), Run, "Review 87 in loupe for conflict."},
		"a local entry wins":               {"appPrompts: true\n" + workFile, withPrompt("implement", "Ignore {cardNumber}."), Run, "Implement 87 " + cardID + " loupe " + projectID + " implement impl.rule " + workRequestID + "."},
		"no opt-in":                        {workFile, withPrompt("review", "Review {cardNumber}."), NoRule, ""},
		"an opt-out":                       {"appPrompts: false\n" + workFile, withPrompt("review", "Review {cardNumber}."), NoRule, ""},
		"no prompt":                        {"appPrompts: true\n" + workFile, withPrompt("review", ""), NoRule, ""},
		"a blank prompt":                   {"appPrompts: true\n" + workFile, withPrompt("review", " \n\t"), NoRule, ""},
		"an unknown placeholder":           {"appPrompts: true\n" + workFile, withPrompt("review", "Review {cardNumber} on {branch}."), NoRule, ""},
	} {
		t.Run(name, func(t *testing.T) {
			text, dir := file(t, strings.Replace(tc.body, "harness: claude-code\n", "harness: claude-code\n    model: opus\n", 1))
			s, err := Parse([]byte(text), Defaults{PermissionMode: "acceptEdits", Model: "sonnet"})
			if err != nil {
				t.Fatal(err)
			}
			checkLoupe(t, s)

			m := s.MatchWork(tc.w)
			if m.Skip != tc.skip {
				t.Fatalf("match = %+v", m)
			}
			if tc.skip != Run {
				return
			}
			if want := directive.Render(tc.prompt, nil); m.Prompt != want {
				t.Fatalf("prompt = %q, want %q", m.Prompt, want)
			}
			if tc.w.Kind != "review" {
				return
			}
			if m.Rule != "work:review" || m.Action != "" || m.Project != "loupe" || m.Dir != dir || m.Pool != DefaultPool ||
				m.PermissionMode != "acceptEdits" || m.Model != "opus" || m.Schema == "" || m.Experiment != nil || m.Before != nil || m.Command != nil {
				t.Fatalf("match = %+v", m)
			}
		})
	}
}

// Dead work runs no app prompt either.
func TestDeadWorkRunsNoAppPrompt(t *testing.T) {
	s := checked(t, "appPrompts: true\n"+workFile)
	s.KillProjectWork("loupe", api.ReasonProjectGone)
	w := workRequest("review")
	w.Prompt = "Review {cardNumber}."

	if m := s.MatchWork(w); m.Skip != NoRule {
		t.Fatalf("match = %+v", m)
	}
}

// A person's resume names the kind and carries no prompt, and still finds the
// worker settings of an app prompt.
func TestMatchKindContinuesTheRunOfAnAppPrompt(t *testing.T) {
	if m := checked(t, "appPrompts: true\n"+workFile).MatchKind(workRequest("review")); m.Skip != Run || m.Action != "" || m.Pool != DefaultPool || m.Rule != "work:review" {
		t.Fatalf("match = %+v", m)
	}
	if m := checked(t, workFile).MatchKind(workRequest("review")); m.Skip != NoRule {
		t.Fatalf("no opt-in: match = %+v", m)
	}
}

// An app prompt run takes the model and effort of the request over the
// account model, and an entry of the kind still wins.
func TestMatchKindAppPromptTakesRequestModelAndEffort(t *testing.T) {
	s := checked(t, "appPrompts: true\n"+strings.Replace(workFile, claudeAccount, claudeAccount+"    model: opus\n", 1))
	w := workRequest("review")
	w.Prompt = "Review."
	w.Model, w.Effort = "haiku", "high"
	if m := s.MatchWork(w); m.Skip != Run || m.Model != "haiku" || m.Effort != "high" {
		t.Fatalf("request values: match = %+v", m)
	}
	w.Model, w.Effort = "", ""
	if m := s.MatchWork(w); m.Skip != Run || m.Model != "opus" || m.Effort != "" {
		t.Fatalf("defaults: match = %+v", m)
	}
	w = workRequest("implement")
	w.Prompt = "Ignore."
	w.Effort = "high"
	if m := s.MatchWork(w); m.Rule != "work:implement" || m.Prompt == "Ignore." {
		t.Fatalf("entry: match = %+v", m)
	}
}

// With appPrompts, an entry of the kind about another subject type leaves the
// request to its app prompt.
func TestAppPromptRunsWhenTheEntryNamesAnotherSubject(t *testing.T) {
	w := workRequest("review")
	w.SubjectType, w.CardNumber = "analysis", 0
	w.Prompt = "Analyse {subjectId}."
	if m := checked(t, "appPrompts: true\n"+workFile).MatchWork(w); m.Skip != Run || !strings.Contains(m.Prompt, "Analyse "+w.SubjectID+".") {
		t.Fatalf("app prompt: match = %+v", m)
	}
	if m := checked(t, workFile).MatchWork(w); m.Skip != NoRule {
		t.Fatalf("no opt-in: match = %+v", m)
	}
}

const paramFile = "projects:\n  loupe:\n    dir: {dir}\nwork:\n  export:\n    action: command\n    description: Export it\n" +
	"    params:\n      out: {pattern: 'var/[a-z-]+', description: The folder}\n    run: [bin/export, '{out}', '{kind}']\n"

func TestParseAcceptsParamsAndDescriptionOnACommandEntry(t *testing.T) {
	set := parse(t, paramFile)
	entries := set.CommandEntries()
	if len(entries) != 1 || entries[0].Name != "export" {
		t.Fatalf("entries = %+v", entries)
	}
	e := entries[0].Entry
	if e.Description != "Export it" || e.Params["out"].Description != "The folder" {
		t.Fatalf("entry = %+v", e)
	}
}

func TestParamMatchesTheWholeValue(t *testing.T) {
	p := Param{Pattern: "var/[a-z-]+|tmp"}
	for value, want := range map[string]bool{
		"var/out": true, "tmp": true, "var/": false, "xvar/out": false, "var/out/..": false,
		"tmpx": false, "var/out\n": false, "": false,
	} {
		if got := p.Match(value); got != want {
			t.Errorf("Match(%q) = %v, want %v", value, got, want)
		}
	}
	if (Param{Pattern: "("}).Match("") {
		t.Error("a pattern that does not compile matched")
	}
}

func TestParseReadsAcceptEntries(t *testing.T) {
	if parse(t, paramFile).AcceptEntries() {
		t.Error("acceptEntries is on without the key")
	}
	if !parse(t, "acceptEntries: true\n"+paramFile).AcceptEntries() {
		t.Error("acceptEntries: true is off")
	}
}

func TestCheckReportSizes(t *testing.T) {
	w := &WorkEntry{Action: ActionCommand, Run: []string{"x"}, Description: strings.Repeat("a", maxReportText+1)}
	if err := checkWork("export", w); err == nil || !strings.Contains(err.Error(), "description is longer") {
		t.Fatalf("want a size error, got %v", err)
	}
}

func TestMatchWorkRemovesTheWorktreeOnTeardown(t *testing.T) {
	s := checked(t, workFile)
	path, err := config.WorktreeDir("loupe", 87)
	if err != nil {
		t.Fatal(err)
	}

	m := s.MatchWork(workRequest("teardown"))
	if m.Skip != Run || m.Rule != "work:teardown" || m.Action != ActionCommand || m.Prompt != "" || m.Command == nil || m.Dir != s.Dir("loupe") {
		t.Fatalf("match = %+v", m)
	}
	if want := []string{"sh", "-c", teardownScript, "sh", path}; !slices.Equal(m.Command.Argv, want) || m.Command.Timeout != DefaultCommandTimeout {
		t.Fatalf("command = %+v, want argv %v", m.Command, want)
	}
}

func TestMatchWorkLeavesTeardownToTheAppWhenTheBridgeDoesNotRemove(t *testing.T) {
	noIsolation := strings.Replace(workFile, "account: claude\n", "account: claude\n  isolation: none\n", 1)
	userEntry := workFile + "  teardown:\n    action: command\n    run: [make, down]\n"
	otherSubject := workRequest("teardown")
	otherSubject.SubjectType = "analysis"
	for name, tc := range map[string]struct {
		body    string
		request api.WorkRequest
		want    Skip
	}{
		"isolation none":    {noIsolation, workRequest("teardown"), NoRule},
		"a teardown entry":  {userEntry, workRequest("teardown"), Run},
		"another subject":   {workFile, otherSubject, NoRule},
		"no card number":    {workFile, func() api.WorkRequest { w := workRequest("teardown"); w.CardNumber = 0; return w }(), NoRule},
		"isolation + app":   {workFile + "appPrompts: true\n", workRequest("teardown"), Run},
		"none + app prompt": {noIsolation + "appPrompts: true\n", func() api.WorkRequest { w := workRequest("teardown"); w.Prompt = "Tear down {cardNumber}."; return w }(), Run},
	} {
		t.Run(name, func(t *testing.T) {
			m := checked(t, tc.body).MatchWork(tc.request)
			if m.Skip != tc.want {
				t.Fatalf("Skip = %v, want %v (match %+v)", m.Skip, tc.want, m)
			}
			if name == "a teardown entry" && (m.Command == nil || !slices.Equal(m.Command.Argv[len(m.Command.Argv)-2:], []string{"make", "down"})) {
				t.Fatalf("the user entry must run, got %+v", m.Command)
			}
			if name == "none + app prompt" && m.Command != nil {
				t.Fatalf("isolation none must run the app prompt, got %+v", m.Command)
			}
		})
	}
}

func TestMatchWorkRunsATeardownEntryAloneWhenTheBridgeDoesNotRemove(t *testing.T) {
	onCards := workFile + "  teardown:\n    action: command\n    run: [make, down]\n"
	noIsolation := strings.Replace(onCards, "account: claude\n", "account: claude\n  isolation: none\n", 1)
	onAnalyses := onCards + "    subject: analysis\n"
	onAnalysis := workRequest("teardown")
	onAnalysis.SubjectType = "analysis"
	byName := workRequest("teardown")
	byName.Capability = CapabilityRunCommand
	for name, tc := range map[string]struct {
		body    string
		request api.WorkRequest
	}{
		"isolation none": {noIsolation, workRequest("teardown")},
		"no card":        {onAnalyses, onAnalysis},
		"a run by name":  {onCards, byName},
	} {
		t.Run(name, func(t *testing.T) {
			m := checked(t, tc.body).MatchKind(tc.request)
			if m.Skip != Run || m.Command == nil || !slices.Equal(m.Command.Argv, []string{"make", "down"}) {
				t.Fatalf("match = %+v, want the entry alone", m)
			}
		})
	}
}

func TestTeardownAfterScriptKeepsTheExitCodeOfTheCommand(t *testing.T) {
	bin := t.TempDir()
	// The fake git fails a removal when GIT_FAILS is set.
	fake := "#!/bin/sh\necho git \"$@\" >> \"$LOG\"\n[ -z \"$GIT_FAILS\" ] || [ \"$1 $2\" != \"worktree remove\" ] || exit 7\n"
	if err := os.WriteFile(filepath.Join(bin, "git"), []byte(fake), 0o755); err != nil {
		t.Fatal(err)
	}
	present := t.TempDir()
	for name, tc := range map[string]struct {
		path, command, gitFails string
		code                    int
		calls                   []string
	}{
		"both pass":           {present, "true", "", 0, []string{"git worktree remove --force " + present, "git worktree prune"}},
		"command fails":       {present, "false", "", 1, []string{"git worktree remove --force " + present, "git worktree prune"}},
		"removal fails":       {present, "true", "1", 7, []string{"git worktree remove --force " + present}},
		"both fail":           {present, "false", "1", 1, []string{"git worktree remove --force " + present}},
		"folder already gone": {filepath.Join(present, "gone"), "true", "", 0, []string{"git worktree prune"}},
	} {
		t.Run(name, func(t *testing.T) {
			log := filepath.Join(t.TempDir(), "log")
			cmd := exec.Command("sh", "-c", teardownAfterScript, "sh", tc.path, tc.command)
			cmd.Env = append(os.Environ(), "PATH="+bin+":"+os.Getenv("PATH"), "LOG="+log, "GIT_FAILS="+tc.gitFails)
			err := cmd.Run()
			code := 0
			if exit, ok := err.(*exec.ExitError); ok {
				code = exit.ExitCode()
			} else if err != nil {
				t.Fatal(err)
			}
			out, _ := os.ReadFile(log)
			calls := strings.Split(strings.TrimSpace(string(out)), "\n")
			if code != tc.code || !slices.Equal(calls, tc.calls) {
				t.Fatalf("exit %d with git calls %q, want %d and %q", code, calls, tc.code, tc.calls)
			}
		})
	}
}

const envFile = claudeAccount + claudeDefaults + `
projects:
  loupe:
    dir: {dir}
work:
  implement:
    prompt: Implement {cardNumber}.
    refresh:
      run: [up, 'card-{cardNumber}', 'card:{cardId}']
      timeout: 20m
    reset:
      run: [down, 'card-{cardNumber}']
  plain:
    prompt: Plain.
`

func TestMatchWorkFillsTheRefreshAndTheReset(t *testing.T) {
	s := checked(t, envFile)

	m := s.MatchWork(workRequest("implement"))
	if m.Refresh == nil || !slices.Equal(m.Refresh.Argv, []string{"up", "card-87", "card:" + cardID}) || m.Refresh.Timeout != 20*time.Minute {
		t.Fatalf("refresh = %+v", m.Refresh)
	}
	if m.Reset == nil || !slices.Equal(m.Reset.Argv, []string{"down", "card-87"}) || m.Reset.Timeout != DefaultBeforeTimeout {
		t.Fatalf("reset = %+v", m.Reset)
	}

	m = s.MatchWork(workRequest("plain"))
	if m.Refresh != nil || m.Reset != nil {
		t.Fatalf("refresh = %+v, reset = %+v, want none", m.Refresh, m.Reset)
	}
}

// A session request appends the suggested prompt last, after the inbox note.
// A blank prompt, or a prompt on another kind, changes nothing.
func TestMatchWorkAppendsTheSuggestedPromptToASession(t *testing.T) {
	s := checked(t, workFile+"  session:\n    action: interactive\n    prompt: base {cardNumber}\n")
	plain := s.MatchWork(workRequest("session"))

	w := workRequest("session")
	w.Prompt = "  Check {cardNumber} please  "
	m := s.MatchWork(w)
	if want := plain.Prompt + "\n\n" + directive.SuggestedPrompt(w.Prompt); m.Skip != Run || m.Prompt != want {
		t.Fatalf("prompt = %q, want %q", m.Prompt, want)
	}
	if !strings.Contains(m.Prompt, "{cardNumber} please") {
		t.Fatalf("prompt = %q, want the braces literal", m.Prompt)
	}

	w.Context.InboxActionID = "01a10beb-ba65-736b-8626-a6e3fa59dfc5"
	w.Context.InboxItemNumber = 12
	w.Context.InboxItemID = "01a137da-4de8-70ae-991f-2ef19584f2a6"
	m = s.MatchWork(w)
	note := directive.InboxNote(12, w.Context.InboxItemID)
	if want := plain.Prompt + "\n\n" + note + "\n\n" + directive.SuggestedPrompt(w.Prompt); m.Prompt != want {
		t.Fatalf("prompt with note = %q, want %q", m.Prompt, want)
	}

	w = workRequest("session")
	w.Prompt = " \n\t"
	if m := s.MatchWork(w); m.Prompt != plain.Prompt {
		t.Fatalf("blank prompt = %q, want %q", m.Prompt, plain.Prompt)
	}

	w = workRequest("pair")
	pairPlain := s.MatchWork(w)
	w.Prompt = "something"
	if m := s.MatchWork(w); m.Prompt != pairPlain.Prompt {
		t.Fatalf("non-session prompt = %q, want %q", m.Prompt, pairPlain.Prompt)
	}
}

func splitWithHeadline(t *testing.T, fields string) *Set {
	t.Helper()
	text, _ := file(t, strings.Replace(workFile, "    metrics: [cost, merge-rate]\n", "    metrics: [cost, merge-rate]\n"+fields, 1))
	s, err := Parse([]byte(text), Defaults{Model: "haiku"})
	if err != nil {
		t.Fatalf("err = %v", err)
	}
	checkLoupe(t, s)

	return s
}

func headlineOf(t *testing.T, fields string) *Headline {
	t.Helper()
	m := splitWithHeadline(t, fields).MatchWork(workRequest("split"))
	if m.Experiment == nil {
		t.Fatal("no experiment")
	}

	return m.Experiment.Headline
}

func TestParseKeepsAbsentAndEmptyGuardsApart(t *testing.T) {
	if h := headlineOf(t, ""); h != nil {
		t.Fatalf("headline = %+v", h)
	}
	h := headlineOf(t, "    headline:\n      goal: {metric: hours-to-merge, better: lower}\n")
	if h == nil || h.Goal == nil || *h.Goal != (Goal{Metric: "hours-to-merge", Better: "lower"}) || h.Guards != nil {
		t.Fatalf("headline = %+v", h)
	}
	h = headlineOf(t, "    headline:\n      guards: []\n")
	if h == nil || h.Goal != nil || h.Guards == nil || len(h.Guards) != 0 {
		t.Fatalf("headline = %+v", h)
	}
	h = headlineOf(t, "    headline:\n      goal: {metric: cost, better: higher}\n      guards: [merge-rate, fix-rounds]\n")
	if h == nil || h.Goal.Better != "higher" || !slices.Equal(h.Guards, []string{"merge-rate", "fix-rounds"}) {
		t.Fatalf("headline = %+v", h)
	}
}

func TestParseKeepsAGoalWithNoDirection(t *testing.T) {
	h := headlineOf(t, "    headline:\n      goal: {metric: cost}\n")
	if h == nil || h.Goal == nil || *h.Goal != (Goal{Metric: "cost"}) {
		t.Fatalf("headline = %+v", h)
	}
}

func TestMatchWorkClonesTheHeadline(t *testing.T) {
	s := splitWithHeadline(t, "    headline:\n      goal: {metric: cost, better: lower}\n      guards: [merge-rate]\n")
	h := s.MatchWork(workRequest("split")).Experiment.Headline
	h.Goal.Metric = "changed"
	h.Guards[0] = "changed"
	again := s.MatchWork(workRequest("split")).Experiment.Headline
	if again.Goal.Metric != "cost" || again.Guards[0] != "merge-rate" {
		t.Fatal("a caller changed the set's headline")
	}
}

func TestParseRefusesTooManyGuards(t *testing.T) {
	keys := make([]string, MaxMetrics+1)
	for i := range keys {
		keys[i] = "m" + strings.Repeat("a", i+1)
	}
	body := "projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    prompt: x\nexperiments:\n  trial:\n    covers: [x]\n    baseline: a\n    headline:\n      guards: [" + strings.Join(keys, ", ") +
		"]\n    variants:\n      - {name: a, weight: 1, model: opus}\n"
	text, _ := file(t, body)
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "headline has 17 guards, and the server takes at most 16") {
		t.Fatalf("err = %v", err)
	}
}

func TestParseKeepsTheControlsOfAHeadline(t *testing.T) {
	h := headlineOf(t, "    headline:\n      controls:\n        - {metric: 'harness-events:diagnostics', variant: a, expect: \"> 0\", share: 0.5}\n")
	if h == nil || h.Goal != nil || h.Guards != nil || len(h.Controls) != 1 {
		t.Fatalf("headline = %+v", h)
	}
	c := h.Controls[0]
	if c.Metric != "harness-events:diagnostics" || c.Variant != "a" || c.Expect != "> 0" || c.Share == nil || *c.Share != 0.5 {
		t.Fatalf("control = %+v", c)
	}
}

func TestMatchWorkClonesTheControls(t *testing.T) {
	s := splitWithHeadline(t, "    headline:\n      controls:\n        - {metric: cost, variant: a, expect: '> 0', share: 0.5}\n")
	h := s.MatchWork(workRequest("split")).Experiment.Headline
	h.Controls[0].Metric = "changed"
	*h.Controls[0].Share = 1
	again := s.MatchWork(workRequest("split")).Experiment.Headline
	if again.Controls[0].Metric != "cost" || *again.Controls[0].Share != 0.5 {
		t.Fatal("a caller changed the set's controls")
	}
}

func TestParseRefusesTooManyControls(t *testing.T) {
	var lines []string
	for i := 0; i <= MaxControls; i++ {
		lines = append(lines, fmt.Sprintf("        - {metric: m%s, variant: a, expect: '> 0', share: 0.5}", strings.Repeat("a", i+1)))
	}
	body := "projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    prompt: x\nexperiments:\n  trial:\n    covers: [x]\n    baseline: a\n    headline:\n      controls:\n" + strings.Join(lines, "\n") +
		"\n    variants:\n      - {name: a, weight: 1, model: opus}\n"
	text, _ := file(t, body)
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "headline has 17 controls, and the server takes at most 16") {
		t.Fatalf("err = %v", err)
	}
}

func TestMatchWorkSkipsAnInteractiveRequestWithoutAnInteractiveEntry(t *testing.T) {
	interactiveRequest := func(kind string) api.WorkRequest {
		w := workRequest(kind)
		w.Capability = CapabilityInteractive
		w.Prompt = "Design {cardNumber}."

		return w
	}
	plain := workRequest("review")
	plain.Prompt = "Review {cardNumber}."
	for name, tc := range map[string]struct {
		body string
		w    api.WorkRequest
		skip Skip
	}{
		"no entry, app prompts on":      {"appPrompts: true\n" + workFile, interactiveRequest("review"), NoRule},
		"a non-interactive entry":       {"appPrompts: true\n" + workFile, interactiveRequest("implement"), NoRule},
		"an interactive entry":          {"appPrompts: true\n" + workFile, interactiveRequest("pair"), Run},
		"a plain request with no entry": {"appPrompts: true\n" + workFile, plain, Run},
	} {
		t.Run(name, func(t *testing.T) {
			s := checked(t, tc.body)
			if m := s.MatchWork(tc.w); m.Skip != tc.skip {
				t.Fatalf("match = %+v", m)
			}
		})
	}
}
