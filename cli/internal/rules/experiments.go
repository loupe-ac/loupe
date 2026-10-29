package rules

import (
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"fmt"
	"regexp"
	"slices"
	"unicode/utf8"
)

// The limits the server puts on an experiment. MaxWeight is the bridge's own,
// so the sum of the weights never overflows.
const (
	MaxVariants    = 32
	MaxModelLength = 100
	MaxWeight      = 1_000_000
	MaxMetrics     = 16
	MaxControls    = 16
)

// experimentNamePattern is the shape the server takes for an experiment or a
// variant name.
var experimentNamePattern = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,63}$`)

// metricKeyPattern is the shape the server takes for a metric key. The bridge
// does not know the server's metrics, so it checks the shape only.
var metricKeyPattern = regexp.MustCompile(`^[a-z][a-z0-9_:-]{0,127}$`)

// expectPattern is the shape the server takes for the expectation of a control:
// a comparison and a number.
var expectPattern = regexp.MustCompile(`^(>=|<=|>|<|==) *-?[0-9]+(\.[0-9]+)?$`)

// modelPattern refuses the control characters the server refuses in a model.
var modelPattern = regexp.MustCompile(`^[^\p{C}]+$`)

// Experiment splits the runs of the rules that join it between models.
type Experiment struct {
	Name     string    `yaml:"name"`
	Variants []Variant `yaml:"variants"`
	Metrics  []string  `yaml:"metrics"`
	Headline *Headline `yaml:"headline"`

	// settings holds the run settings of each variant, in variant order.
	settings []RunSettings
}

// Headline is the goal, the guards and the controls of an experiment. A nil
// Guards means the server's default guards, and an empty Guards means no
// guards.
type Headline struct {
	Goal     *Goal     `yaml:"goal"`
	Guards   []string  `yaml:"guards"`
	Controls []Control `yaml:"controls"`
}

// Control checks that a variant got its treatment. It holds when at least
// Share of the cards of the variant that have a value for Metric meet Expect.
type Control struct {
	Metric  string   `yaml:"metric"`
	Variant string   `yaml:"variant"`
	Expect  string   `yaml:"expect"`
	Share   *float64 `yaml:"share"`
}

// Goal is the metric an experiment tries to move, and the direction that
// counts as better. Better is lower, higher, or empty when the rule names
// none.
type Goal struct {
	Metric string `yaml:"metric"`
	Better string `yaml:"better"`
}

// Variant is one model or account of an experiment, and its share of the
// cards.
type Variant struct {
	Name        string `yaml:"name"`
	Weight      int    `yaml:"weight"`
	Model       string `yaml:"model"`
	Account     string `yaml:"account"`
	Permissions string `yaml:"permissions"`
}

// Settings are the run settings of a variant. A variant the experiment does
// not hold runs with its own model alone.
func (e Experiment) Settings(v Variant) RunSettings {
	i := slices.IndexFunc(e.Variants, func(o Variant) bool { return o.Name == v.Name })
	if i < 0 || i >= len(e.settings) {
		return RunSettings{Model: v.Model}
	}

	return e.settings[i].clone()
}

// Pick is the variant the bridge proposes for a card. The same experiment and
// card key always give the same variant, and each variant takes its weight's
// share of the cards. It returns the zero Variant for an experiment with no
// weight.
func (e Experiment) Pick(cardKey string) Variant {
	total := 0
	for _, v := range e.Variants {
		total += v.Weight
	}
	if total <= 0 {
		return Variant{}
	}
	sum := sha256.Sum256([]byte(e.Name + ":" + cardKey))
	n := int(binary.BigEndian.Uint64(sum[:8]) % uint64(total))
	for _, v := range e.Variants {
		if n < v.Weight {
			return v
		}
		n -= v.Weight
	}

	return Variant{}
}

// clone copies the variants and the metrics, so a caller cannot change the
// set's experiment.
func (e Experiment) clone() *Experiment {
	e.Variants = slices.Clone(e.Variants)
	e.Metrics = slices.Clone(e.Metrics)
	e.Headline = e.Headline.clone()
	e.settings = slices.Clone(e.settings)
	for i := range e.settings {
		e.settings[i] = e.settings[i].clone()
	}

	return &e
}

func (h *Headline) clone() *Headline {
	if h == nil {
		return nil
	}
	out := &Headline{Guards: slices.Clone(h.Guards), Controls: slices.Clone(h.Controls)}
	for i := range out.Controls {
		if share := out.Controls[i].Share; share != nil {
			copied := *share
			out.Controls[i].Share = &copied
		}
	}
	if h.Goal != nil {
		goal := *h.Goal
		out.Goal = &goal
	}

	return out
}

func checkExperiment(e Experiment) error {
	var errs []error
	if !experimentNamePattern.MatchString(e.Name) {
		errs = append(errs, errNamePattern)
	}
	switch {
	case len(e.Variants) == 0:
		errs = append(errs, errors.New("it has no variants"))
	case len(e.Variants) > MaxVariants:
		errs = append(errs, fmt.Errorf("it has %d variants, and the server takes at most %d", len(e.Variants), MaxVariants))
	}
	names := map[string]bool{}
	for _, v := range e.Variants {
		if names[v.Name] {
			errs = append(errs, fmt.Errorf("two variants are named %q", v.Name))

			continue
		}
		names[v.Name] = true
		if err := checkVariant(v); err != nil {
			errs = append(errs, fmt.Errorf("variant %q: %w", v.Name, err))
		}
	}

	errs = append(errs, checkMetrics(e.Metrics)...)
	errs = append(errs, checkHeadline(e.Headline, names)...)

	return errors.Join(errs...)
}

func checkMetrics(metrics []string) []error {
	var errs []error
	if len(metrics) > MaxMetrics {
		errs = append(errs, fmt.Errorf("it has %d metrics, and the server takes at most %d", len(metrics), MaxMetrics))
	}
	seen := map[string]bool{}
	for _, key := range metrics {
		switch {
		case seen[key]:
			errs = append(errs, fmt.Errorf("metric %q is listed twice", key))
		case !metricKeyPattern.MatchString(key):
			errs = append(errs, fmt.Errorf("metric %q: a metric key is 1 to 128 lowercase letters, digits, underscores, colons and hyphens, and starts with a letter, such as merge-rate", key))
		}
		seen[key] = true
	}

	return errs
}

func checkHeadline(h *Headline, variants map[string]bool) []error {
	if h == nil {
		return nil
	}
	var errs []error
	if h.Goal == nil && h.Guards == nil && len(h.Controls) == 0 {
		errs = append(errs, errors.New("headline needs a goal, guards or controls"))
	}
	errs = append(errs, checkControls(h.Controls, variants)...)
	if h.Goal != nil {
		if !metricKeyPattern.MatchString(h.Goal.Metric) {
			errs = append(errs, fmt.Errorf("headline goal: metric %q: a metric key is 1 to 128 lowercase letters, digits, underscores, colons and hyphens, and starts with a letter, such as merge-rate", h.Goal.Metric))
		}
		// An empty Better loads, so the Comparison tab can ask for a direction.
		switch h.Goal.Better {
		case "", "lower", "higher":
		default:
			errs = append(errs, fmt.Errorf("headline goal: better is lower or higher, got %q", h.Goal.Better))
		}
	}
	if len(h.Guards) > MaxMetrics {
		errs = append(errs, fmt.Errorf("headline has %d guards, and the server takes at most %d", len(h.Guards), MaxMetrics))
	}
	seen := map[string]bool{}
	for _, key := range h.Guards {
		switch {
		case seen[key]:
			errs = append(errs, fmt.Errorf("headline guard %q is listed twice", key))
		case !metricKeyPattern.MatchString(key):
			errs = append(errs, fmt.Errorf("headline guard %q: a metric key is 1 to 128 lowercase letters, digits, underscores, colons and hyphens, and starts with a letter, such as merge-rate", key))
		case h.Goal != nil && key == h.Goal.Metric:
			errs = append(errs, fmt.Errorf("headline guard %q is also the goal metric", key))
		}
		seen[key] = true
	}

	return errs
}

func checkControls(controls []Control, variants map[string]bool) []error {
	var errs []error
	if len(controls) > MaxControls {
		errs = append(errs, fmt.Errorf("headline has %d controls, and the server takes at most %d", len(controls), MaxControls))
	}
	seen := map[[2]string]bool{}
	for _, c := range controls {
		name := fmt.Sprintf("headline control %q on variant %q", c.Metric, c.Variant)
		switch {
		case !metricKeyPattern.MatchString(c.Metric):
			errs = append(errs, fmt.Errorf("%s: a metric key is 1 to 128 lowercase letters, digits, underscores, colons and hyphens, and starts with a letter, such as harness-events:diagnostics", name))
		case !variants[c.Variant]:
			errs = append(errs, fmt.Errorf("%s: the experiment declares no variant %q", name, c.Variant))
		case seen[[2]string{c.Metric, c.Variant}]:
			errs = append(errs, fmt.Errorf("%s is listed twice", name))
		}
		seen[[2]string{c.Metric, c.Variant}] = true
		if !expectPattern.MatchString(c.Expect) {
			errs = append(errs, fmt.Errorf("%s: expect %q is a comparison and a number, such as \"> 0\" or \">= 3\"", name, c.Expect))
		}
		switch {
		case c.Share == nil:
			errs = append(errs, fmt.Errorf("%s: share is required, a fraction above 0 and at most 1, such as 0.5", name))
		case !(*c.Share > 0 && *c.Share <= 1):
			errs = append(errs, fmt.Errorf("%s: share must be above 0 and at most 1, got %v", name, *c.Share))
		}
	}

	return errs
}

func checkVariant(v Variant) error {
	var errs []error
	if !experimentNamePattern.MatchString(v.Name) {
		errs = append(errs, errNamePattern)
	}
	switch {
	case v.Weight < 1:
		errs = append(errs, fmt.Errorf("weight must be at least 1, got %d", v.Weight))
	case v.Weight > MaxWeight:
		errs = append(errs, fmt.Errorf("weight must be at most %d, got %d", MaxWeight, v.Weight))
	}
	switch n := utf8.RuneCountInString(v.Model); {
	case v.Model == "" && v.Account == "":
		errs = append(errs, errors.New("model or account is required"))
	case v.Model == "":
	case n > MaxModelLength:
		errs = append(errs, fmt.Errorf("model is %d characters, and the server takes at most %d", n, MaxModelLength))
	case !modelPattern.MatchString(v.Model):
		errs = append(errs, fmt.Errorf("model %q holds a control character", v.Model))
	default:
		if err := checkWord("model", v.Model); err != nil {
			errs = append(errs, err)
		}
	}

	return errors.Join(errs...)
}

var errNamePattern = errors.New("a name is 1 to 64 lowercase letters, digits, hyphens and underscores, and starts with a letter or a digit, such as impl-model")
