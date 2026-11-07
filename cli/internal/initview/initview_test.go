package initview

import (
	"bytes"
	"strings"
	"testing"
)

func TestFlushAlignsNamesAndFoldsSetUpSteps(t *testing.T) {
	var out bytes.Buffer
	w := NewWriter(&out, false)
	w.Record(Step{Name: "Project", State: "done", Short: "using Dev"})
	w.Record(Step{Name: "Sign in", State: "skipped", Fold: true})
	w.Record(Step{Name: "MCP", State: "skipped", Fold: true})
	w.Record(Step{Name: "Bridge", State: "failed", Short: "it did not start"})
	w.Finish()

	want := "✓ Project         using Dev\n✗ Bridge          it did not start\n· Already set up: sign in, mcp\n"
	if out.String() != want {
		t.Errorf("output = %q, want %q", out.String(), want)
	}
}

// A write between steps flushes them, and the report still keeps one name
// column and one folded line.
func TestFlushesKeepOneColumnAndOneFoldedLine(t *testing.T) {
	var out bytes.Buffer
	w := NewWriter(&out, false)
	w.Record(Step{Name: "Sign in", State: "skipped", Fold: true})
	w.Record(Step{Name: "Link", State: "done", Short: "linked"})
	_, _ = w.Write([]byte("table\n"))
	w.Record(Step{Name: "Profile", State: "skipped", Fold: true})
	w.Record(Step{Name: "Codex approval", State: "done", Short: "approved"})
	w.Finish()
	w.Finish()

	want := "✓ Link            linked\ntable\n✓ Codex approval  approved\n· Already set up: sign in, profile\n"
	if out.String() != want {
		t.Errorf("output = %q, want %q", out.String(), want)
	}
}

func TestAnotherWriteFlushesThePendingStepsFirst(t *testing.T) {
	var out bytes.Buffer
	w := NewWriter(&out, false)
	w.Record(Step{Name: "Link", State: "done", Short: "linked"})
	_, _ = w.Write([]byte("Which project?\n"))

	if got := out.String(); got != "✓ Link            linked\nWhich project?\n" {
		t.Errorf("output = %q", got)
	}
}

func TestVerboseKeepsTheReason(t *testing.T) {
	var out bytes.Buffer
	w := NewWriter(&out, true)
	w.Record(Step{Name: "Link", State: "done", Short: "linked", Reason: "wrote the file"})
	w.Flush()

	if got := out.String(); got != "Link: done, wrote the file.\n" {
		t.Errorf("output = %q", got)
	}
}

func TestBriefCutsAtTheFirstSentence(t *testing.T) {
	if got := Brief("installed loupe. Restart Claude Code to load it"); got != "installed loupe" {
		t.Errorf("Brief = %q", got)
	}
	if got := Brief(strings.Repeat("a", 120)); len([]rune(got)) != 100 {
		t.Errorf("Brief length = %d", len([]rune(got)))
	}
}

func TestTableHasNoEscapeCodesOutsideATerminal(t *testing.T) {
	out := Table(&bytes.Buffer{}, []string{"A", "B"}, [][]string{{"x", "y"}})
	if strings.Contains(out, "\x1b") || !strings.Contains(out, "x") {
		t.Errorf("table = %q", out)
	}
}

func TestHomePathsShortenInPromptsAndVerboseSteps(t *testing.T) {
	t.Setenv("HOME", "/home/ann")

	var out bytes.Buffer
	w := NewWriter(&out, true)
	n, err := w.Write([]byte("Set up /home/ann/code/app or /home/ann/code?\n"))
	if err != nil || n != len("Set up /home/ann/code/app or /home/ann/code?\n") {
		t.Errorf("Write = %d, %v", n, err)
	}
	w.Record(Step{Name: "Codex approval", State: "done", Reason: "wrote /home/ann/.codex/config.toml"})
	w.Flush()

	want := "Set up ~/code/app or ~/code?\nCodex approval: done, wrote ~/.codex/config.toml.\n"
	if out.String() != want {
		t.Errorf("output = %q, want %q", out.String(), want)
	}
}

func TestShortPathKeepsAFolderThatOnlySharesTheStart(t *testing.T) {
	t.Setenv("HOME", "/home/ann")

	cases := map[string]string{
		"/home/ann":                "~",
		"/home/ann/x":              "~/x",
		"in /home/ann.":            "in ~.",
		"/home/ann2/x":             "/home/ann2/x",
		"/home/ann_b, /home/ann/c": "/home/ann_b, ~/c",
		"/home/annü/x":             "/home/annü/x",
		"/srv/home/ann/x":          "/srv/home/ann/x",
		"(/home/ann/x)":            "(~/x)",
		"/home/ann.backup/x":       "/home/ann.backup/x",
		"/home/ann..x":             "/home/ann..x",
		"/home/ann+old/x":          "/home/ann+old/x",
		"/home/ann@host/x":         "/home/ann@host/x",
		"see /home/ann...":         "see ~...",
		"at /home/ann, then":       "at ~, then",
		"\"/home/ann\"":            "\"~\"",
	}
	for in, want := range cases {
		if got := ShortPath(in); got != want {
			t.Errorf("ShortPath(%q) = %q, want %q", in, got, want)
		}
	}
}
