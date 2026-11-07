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
	w.Flush()

	want := "✓ Project  using Dev\n✗ Bridge   it did not start\n· Already set up: sign in, mcp\n"
	if out.String() != want {
		t.Errorf("output = %q, want %q", out.String(), want)
	}
}

func TestAnotherWriteFlushesThePendingStepsFirst(t *testing.T) {
	var out bytes.Buffer
	w := NewWriter(&out, false)
	w.Record(Step{Name: "Link", State: "done", Short: "linked"})
	_, _ = w.Write([]byte("Which project?\n"))

	if got := out.String(); got != "✓ Link  linked\nWhich project?\n" {
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
