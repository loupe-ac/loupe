package rules

import (
	"fmt"
	"math/rand/v2"
	"slices"
	"strings"
	"testing"
)

// experimentFile builds a rule file from work entries and an experiments block.
func experimentFile(work, experiments string) string {
	return claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nwork:\n" + work + experiments
}

const (
	implementAndFix = "  implement:\n    prompt: x\n  fix:\n    prompt: x\n"
	twoVariants     = "    variants:\n      - {name: a, weight: 1, model: opus}\n      - {name: b, weight: 1, model: sonnet}\n"
)

// trial is an experiment block of the given name, covers and extra fields.
func trial(name, covers, extra string) string {
	return "experiments:\n  " + name + ":\n    covers: " + covers + "\n    baseline: a\n" + extra
}

func parseErr(t *testing.T, body string) error {
	t.Helper()
	text, _ := file(t, body)
	_, err := Parse([]byte(text), Defaults{})

	return err
}

func wantErr(t *testing.T, body string, wants ...string) {
	t.Helper()
	err := parseErr(t, body)
	for _, want := range wants {
		if err == nil || !strings.Contains(err.Error(), want) {
			t.Fatalf("err = %v, want it to contain %q", err, want)
		}
	}
}

// Each variant of an experiment gets the checks an experiment has.
func TestParseRefusesInvalidVariants(t *testing.T) {
	body := func(variants ...string) string {
		return experimentFile(implementAndFix, trial("trial", "[implement]", "    variants:"+strings.Join(variants, "")+"\n"))
	}
	variant := func(name string, weight int, model string) string {
		return fmt.Sprintf("\n      - {name: %q, weight: %d, model: %q}", name, weight, model)
	}

	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"no variants":         {experimentFile(implementAndFix, trial("trial", "[implement]", "    variants: []\n")), "it has no variants"},
		"zero weight":         {body(variant("a", 0, "opus")), `variant "a": weight must be at least 1, got 0`},
		"negative weight":     {body(variant("a", -2, "opus")), `variant "a": weight must be at least 1, got -2`},
		"weight over the cap": {body(variant("a", MaxWeight+1, "opus")), fmt.Sprintf(`variant "a": weight must be at most %d`, MaxWeight)},
		"model with a space":  {body(variant("a", 1, "opus 5")), `variant "a": model "opus 5" holds whitespace`},
		"model over 100":      {body(variant("a", 1, strings.Repeat("m", MaxModelLength+1))), `variant "a": model is 101 characters, and the server takes at most 100`},
		"duplicate variant":   {body(variant("a", 1, "opus"), variant("a", 1, "sonnet")), `two variants are named "a"`},
		"bad variant name":    {body(variant("-a", 1, "opus")), `variant "-a": a name is 1 to 64 lowercase letters`},
		"blank variant name":  {body(variant("", 1, "opus")), `variant "": a name is 1 to 64 lowercase letters`},
		"kinds model space": {experimentFile(implementAndFix, trial("trial", "[implement]", "    variants:\n      - {name: a, weight: 1, kinds: {implement: {model: 'opus 5'}}}\n")),
			`variant "a": kind "implement": model "opus 5" holds whitespace`},
		"kinds model too long": {experimentFile(implementAndFix, trial("trial", "[implement]", "    variants:\n      - {name: a, weight: 1, kinds: {implement: {model: "+strings.Repeat("m", MaxModelLength+1)+"}}}\n")),
			`variant "a": kind "implement": model is 101 characters`},
	} {
		t.Run(name, func(t *testing.T) {
			wantErr(t, tc.body, tc.want)
		})
	}
}

func TestExperimentIsNamedByItsMapKey(t *testing.T) {
	s := checked(t, experimentFile(implementAndFix, trial("implement-php-lsp", "[implement, fix]", twoVariants)))
	for _, kind := range []string{"implement", "fix"} {
		m := s.MatchWork(workRequest(kind))
		if m.Experiment == nil || m.Experiment.Name != "implement-php-lsp" || !slices.Equal(m.Experiment.Covers, []string{"fix", "implement"}) {
			t.Fatalf("%s: experiment = %+v", kind, m.Experiment)
		}
	}
}

func TestExperimentNameChangesTheDraw(t *testing.T) {
	a := Experiment{Name: "implement", Variants: []Variant{{Name: "a", Weight: 1}, {Name: "b", Weight: 1}}}
	b := a
	b.Name = "implement-php-lsp"
	rng := rand.New(rand.NewPCG(1, 2))
	for range 200 {
		id := uuidV7(rng)
		if a.Pick(id).Name != b.Pick(id).Name {
			return
		}
	}
	t.Fatal("two names drew the same variant for 200 cards")
}

func TestParseRefusesAnInvalidExperiment(t *testing.T) {
	both := trial("one", "[implement]", twoVariants) + "  two:\n    covers: [implement]\n    baseline: a\n" + twoVariants
	for name, tc := range map[string]struct {
		body  string
		wants []string
	}{
		"bad name":        {experimentFile(implementAndFix, trial("Php LSP", "[implement]", twoVariants)), []string{`experiment "Php LSP"`, "a name is 1 to 64 lowercase letters"}},
		"empty covers":    {experimentFile(implementAndFix, trial("trial", "[]", twoVariants)), []string{`experiment "trial"`, "covers lists no kind"}},
		"absent covers":   {experimentFile(implementAndFix, "experiments:\n  trial:\n"+twoVariants), []string{`experiment "trial"`, "covers lists no kind"}},
		"duplicate cover": {experimentFile(implementAndFix, trial("trial", "[implement, fix, implement]", twoVariants)), []string{`experiment "trial"`, `covers lists "implement" twice`}},
		"no work entry":   {experimentFile(implementAndFix, trial("trial", "[implement, plan]", twoVariants)), []string{`experiment "trial"`, `covers kind "plan", and work has no entry for it`}},
		"interactive entry": {experimentFile("  pair:\n    action: interactive\n    prompt: x\n", trial("trial", "[pair]", twoVariants)),
			[]string{`experiment "trial"`, `kind "pair"`, "worker entry for a card only"}},
		"command entry": {experimentFile("  test:\n    action: command\n    run: [make]\n", trial("trial", "[test]", twoVariants)),
			[]string{`experiment "trial"`, `kind "test"`, "worker entry for a card only"}},
		"non-card subject": {experimentFile("  scan:\n    subject: analysis\n    prompt: x\n", trial("trial", "[scan]", twoVariants)),
			[]string{`experiment "trial"`, `kind "scan"`, "worker entry for a card only"}},
		"kind in two experiments": {experimentFile(implementAndFix, both), []string{`experiment "two"`, `kind "implement"`, `experiment "one"`, "one kind joins one experiment"}},
		"kinds key outside covers": {experimentFile(implementAndFix, trial("trial", "[implement]", "    variants:\n      - {name: a, weight: 1, kinds: {fix: {model: opus}}}\n")),
			[]string{`experiment "trial"`, `variant "a"`, `kinds names "fix", and covers does not list it`}},
		"no variants": {experimentFile(implementAndFix, trial("trial", "[implement]", "")), []string{`experiment "trial"`, "it has no variants"}},
	} {
		t.Run(name, func(t *testing.T) {
			wantErr(t, tc.body, tc.wants...)
		})
	}
}

// A work entry holds no experiment key, and the message says where it moved.
func TestParseRefusesAnExperimentKeyOnAWorkEntry(t *testing.T) {
	for key, value := range map[string]string{
		"experiment": "implement-php-lsp",
		"variants":   "[{name: a, weight: 1, model: opus}]",
		"metrics":    "[cost]",
		"headline":   "{guards: [cost]}",
	} {
		t.Run(key, func(t *testing.T) {
			body := experimentFile("  implement:\n    prompt: x\n    "+key+": "+value+"\n", "")
			wantErr(t, body, "work entry implement sets "+key+"; move the variants, metrics and headline of the experiment into an experiments: block, and list implement under its covers")
		})
	}
}

func TestParseRefusesTheOldExperimentsList(t *testing.T) {
	body := experimentFile(implementAndFix, "experiments:\n  - {name: models, variants: [{name: a, weight: 1, model: opus}]}\n")
	wantErr(t, body, "the experiments list is gone", "map each experiment name under experiments")
}

func TestAFileWithNoExperimentsRunsThePlainEntry(t *testing.T) {
	s := checked(t, experimentFile("  implement:\n    prompt: x\n    model: haiku\n", ""))
	m := s.MatchWork(workRequest("implement"))
	if m.Skip != Run || m.Experiment != nil || m.Model != "haiku" {
		t.Fatalf("match = %+v", m)
	}
}

func TestAKindTheExperimentsLeaveOutHasNoExperiment(t *testing.T) {
	s := checked(t, experimentFile(implementAndFix+"  review:\n    prompt: x\n", trial("trial", "[implement, fix]", twoVariants)))
	if m := s.MatchWork(workRequest("review")); m.Skip != Run || m.Experiment != nil {
		t.Fatalf("review: match = %+v", m)
	}
	if m := s.MatchWork(workRequest("fix")); m.Experiment == nil {
		t.Fatal("fix: no experiment")
	}
}

func TestAVariantWithSettingsOnlyUnderKindsLoads(t *testing.T) {
	s := checked(t, experimentFile("  implement:\n    prompt: x\n    model: entry\n  fix:\n    prompt: x\n    model: entry\n",
		trial("trial", "[implement, fix]", "    variants:\n      - {name: a, weight: 1, kinds: {fix: {model: opus}}}\n      - {name: b, weight: 1}\n")))
	impl, fix := s.MatchWork(workRequest("implement")), s.MatchWork(workRequest("fix"))
	if got := impl.ApplyVariant(impl.Experiment.Variants[0]).Model; got != "entry" {
		t.Fatalf("implement on a = %q", got)
	}
	if got := fix.ApplyVariant(fix.Experiment.Variants[0]).Model; got != "opus" {
		t.Fatalf("fix on a = %q", got)
	}
}

func TestAVariantWithNoSettingsRunsEachKindOnItsEntry(t *testing.T) {
	s := checked(t, experimentFile("  implement:\n    prompt: x\n    model: one\n  fix:\n    prompt: x\n    model: two\n",
		trial("trial", "[implement, fix]", "    variants:\n      - {name: a, weight: 1}\n")))
	for kind, want := range map[string]string{"implement": "one", "fix": "two"} {
		m := s.MatchWork(workRequest(kind))
		if got := m.ApplyVariant(m.Experiment.Variants[0]).Model; got != want {
			t.Fatalf("%s = %q, want %q", kind, got, want)
		}
	}
}

func TestAVariantRunsOnTheAccountOfItsCoveredEntry(t *testing.T) {
	body := "accounts:\n  claude:\n    harness: claude-code\n  other:\n    harness: claude-code\n" + claudeDefaults +
		"projects:\n  loupe:\n    dir: {dir}\nwork:\n  implement:\n    prompt: x\n    account: other\n" +
		trial("trial", "[implement]", "    variants:\n      - {name: a, weight: 1}\n")
	s := checked(t, body)
	m := s.MatchWork(workRequest("implement"))
	if got := m.ApplyVariant(m.Experiment.Variants[0]); got.Account != "other" {
		t.Fatalf("account = %q", got.Account)
	}
}

// A setting takes the first value that is set: the kinds entry of the
// variant, the variant, the work entry, then the account.
func TestSettingsFollowTheLookupOrder(t *testing.T) {
	body := "accounts:\n  claude:\n    harness: claude-code\n    model: acct-model\n" +
		"  a2:\n    harness: claude-code\n  a3:\n    harness: claude-code\n  a4:\n    harness: claude-code\n" + claudeDefaults +
		"projects:\n  loupe:\n    dir: {dir}\nwork:\n" +
		"  implement:\n    prompt: x\n    model: entry-model\n    account: a2\n" +
		"  fix:\n    prompt: x\n    model: entry-model\n    account: a2\n" +
		"  plain:\n    prompt: x\n" +
		strings.Replace(trial("trial", "[implement, fix, plain]", "    variants:\n      - {name: v0, weight: 1}\n"+
			"      - {name: v1, weight: 1, model: variant-model, account: a3, kinds: {fix: {model: kind-model, account: a4}}}\n"), "baseline: a", "baseline: v0", 1)
	s := checked(t, body)
	for _, tc := range []struct{ kind, variant, model, account string }{
		{"implement", "v0", "entry-model", "a2"},
		{"fix", "v0", "entry-model", "a2"},
		{"plain", "v0", "acct-model", "claude"},
		{"implement", "v1", "variant-model", "a3"},
		{"fix", "v1", "kind-model", "a4"},
		{"plain", "v1", "variant-model", "a3"},
	} {
		m := s.MatchWork(workRequest(tc.kind))
		i := slices.IndexFunc(m.Experiment.Variants, func(v Variant) bool { return v.Name == tc.variant })
		got := m.ApplyVariant(m.Experiment.Variants[i])
		if got.Model != tc.model || got.Account != tc.account {
			t.Fatalf("%s on %s: model = %q, account = %q, want %q and %q", tc.kind, tc.variant, got.Model, got.Account, tc.model, tc.account)
		}
	}
}

// The golden picks pin the hash, so every bridge draws the same candidate.
func TestPickIsDeterministic(t *testing.T) {
	for _, tc := range []struct {
		weights [2]int
		want    string
	}{
		{[2]int{1, 1}, "ababbb"},
		{[2]int{1, 3}, "bbabbb"},
	} {
		e := Experiment{Name: "impl-model", Variants: []Variant{
			{Name: "a", Weight: tc.weights[0], Model: "opus"},
			{Name: "b", Weight: tc.weights[1], Model: "sonnet"},
		}}
		got := ""
		for i := range 6 {
			got += e.Pick(fmt.Sprintf("card-%d", i+1)).Name
		}
		if got != tc.want || e.Pick("card-1").Name != e.Pick("card-1").Name {
			t.Fatalf("weights %v: picks = %s, want %s", tc.weights, got, tc.want)
		}
	}

	e := Experiment{Name: "impl-model", Variants: []Variant{{Name: "a", Weight: 1, Model: "opus"}, {Name: "b", Weight: 1, Model: "sonnet"}}}
	other := Experiment{Name: "other", Variants: e.Variants}
	seen := map[bool]bool{}
	for i := range 64 {
		key := fmt.Sprintf("card-%d", i)
		seen[e.Pick(key).Name == other.Pick(key).Name] = true
	}
	if !seen[false] {
		t.Fatal("the experiment name does not change the pick")
	}
}

func TestPickSpreadsByWeight(t *testing.T) {
	rng := rand.New(rand.NewPCG(1, 2))
	for _, weights := range [][2]int{{1, 1}, {1, 3}} {
		e := Experiment{Name: "impl-model", Variants: []Variant{
			{Name: "a", Weight: weights[0], Model: "opus"},
			{Name: "b", Weight: weights[1], Model: "sonnet"},
		}}
		const n = 10000
		first := 0
		for range n {
			if e.Pick(uuidV7(rng)).Name == "a" {
				first++
			}
		}
		want := n * weights[0] / (weights[0] + weights[1])
		if diff := first - want; diff < -300 || diff > 300 {
			t.Fatalf("weights %v: %d of %d picked a, want about %d", weights, first, n, want)
		}
	}
}

// uuidV7 is a random id in the shape of a uuid v7, with a timestamp near now.
func uuidV7(rng *rand.Rand) string {
	ms := 0x0192f3a1_0000 + rng.Uint64N(1<<32)
	a, b := rng.Uint64(), rng.Uint64()

	return fmt.Sprintf("%08x-%04x-7%03x-%04x-%012x", ms>>16, ms&0xffff, a&0xfff, 0x8000|(b>>48)&0x3fff, b&0xffffffffffff)
}

func TestMetricKeyPatternTakesBucketAndHarnessEventKeys(t *testing.T) {
	for _, key := range []string{"harness-events:edited_text_file", "bucket-calls:tests", "bucket-time-all:" + strings.Repeat("a", 64)} {
		if !metricKeyPattern.MatchString(key) {
			t.Errorf("metric key %q was refused", key)
		}
	}
	for _, key := range []string{"Cost", "_cost", "c" + strings.Repeat("o", 128)} {
		if metricKeyPattern.MatchString(key) {
			t.Errorf("metric key %q was accepted", key)
		}
	}
}

func TestParseRefusesAMissingOrUnknownBaseline(t *testing.T) {
	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"absent": {experimentFile(implementAndFix, "experiments:\n  trial:\n    covers: [implement]\n"+twoVariants),
			`experiment "trial": it has no baseline: add baseline: with one of its variant names`},
		"empty": {experimentFile(implementAndFix, "experiments:\n  trial:\n    covers: [implement]\n    baseline: \"\"\n"+twoVariants),
			`experiment "trial": it has no baseline`},
		"unknown": {experimentFile(implementAndFix, "experiments:\n  trial:\n    covers: [implement]\n    baseline: c\n"+twoVariants),
			`experiment "trial": baseline "c" names no variant of the experiment`},
	} {
		t.Run(name, func(t *testing.T) {
			wantErr(t, tc.body, tc.want)
		})
	}
}

func TestParseKeepsTheBaselineOfTheExperiment(t *testing.T) {
	s := checked(t, experimentFile(implementAndFix, strings.Replace(trial("trial", "[implement]", twoVariants), "baseline: a", "baseline: b", 1)))
	if got := s.MatchWork(workRequest("implement")).Experiment.Baseline; got != "b" {
		t.Fatalf("baseline = %q, want b", got)
	}
}
