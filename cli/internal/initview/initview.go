// Package initview draws the output of `loupe init`: one aligned line for each
// step, a dimmed line for the steps that were set up already, and a table.
package initview

import (
	"io"
	"os"
	"strings"

	"github.com/charmbracelet/lipgloss"
	"github.com/charmbracelet/lipgloss/table"
	"github.com/muesli/termenv"
	"golang.org/x/term"
)

// Step is one line the setup owes.
type Step struct {
	Name   string
	State  string
	Short  string
	Reason string
	// Fold marks a skipped step that was set up already. The plain output
	// folds those steps into one line.
	Fold bool
}

// Writer wraps the output of init. It holds the steps that were recorded and
// not drawn yet. Any other write flushes them first, so the order on screen
// stays the order of the calls.
type Writer struct {
	out      io.Writer
	verbose  bool
	renderer *lipgloss.Renderer
	pending  []Step
}

// NewWriter wraps out. It draws colour only when out is a terminal and
// NO_COLOR is unset.
func NewWriter(out io.Writer, verbose bool) *Writer {
	r := lipgloss.NewRenderer(out)
	if !isColor(out) {
		r.SetColorProfile(termenv.Ascii)
	}

	return &Writer{out: out, verbose: verbose, renderer: r}
}

func isColor(out io.Writer) bool {
	if os.Getenv("NO_COLOR") != "" {
		return false
	}
	f, ok := out.(*os.File)

	return ok && term.IsTerminal(int(f.Fd()))
}

// Write flushes the pending steps, then writes p.
func (w *Writer) Write(p []byte) (int, error) {
	w.Flush()

	return w.out.Write(p)
}

// Record adds a step to the pending lines.
func (w *Writer) Record(s Step) {
	w.pending = append(w.pending, s)
}

// Flush draws the pending steps.
func (w *Writer) Flush() {
	if len(w.pending) == 0 {
		return
	}
	steps := w.pending
	w.pending = nil
	_, _ = io.WriteString(w.out, w.render(steps))
}

func (w *Writer) render(steps []Step) string {
	dim := w.renderer.NewStyle().Faint(true)
	good := w.renderer.NewStyle().Foreground(lipgloss.Color("2"))
	bad := w.renderer.NewStyle().Foreground(lipgloss.Color("1"))
	warn := w.renderer.NewStyle().Foreground(lipgloss.Color("3"))

	if w.verbose {
		return w.renderVerbose(steps)
	}

	width := 0
	var skipped []string
	var shown []Step
	for _, s := range steps {
		if s.Fold {
			skipped = append(skipped, strings.ToLower(s.Name))

			continue
		}
		shown = append(shown, s)
		width = max(width, lipgloss.Width(s.Name))
	}

	var b strings.Builder
	for _, s := range shown {
		b.WriteString(mark(s.State, good, bad, warn) + " " + pad(s.Name, width) + "  " + s.Short + "\n")
	}
	if len(skipped) > 0 {
		b.WriteString(dim.Render("· Already set up: "+strings.Join(skipped, ", ")) + "\n")
	}

	return b.String()
}

// renderVerbose draws each step on its own line, with its full reason and no
// mark, the form that a script reads.
func (w *Writer) renderVerbose(steps []Step) string {
	var b strings.Builder
	for _, s := range steps {
		b.WriteString(s.Name + ": " + s.State + ", " + s.Reason + ".\n")
	}

	return b.String()
}

func mark(state string, good, bad, warn lipgloss.Style) string {
	switch state {
	case "done":
		return good.Render("✓")
	case "failed":
		return bad.Render("✗")
	case "skipped":
		return "·"
	default:
		return warn.Render("…")
	}
}

func pad(s string, width int) string {
	return s + strings.Repeat(" ", max(0, width-lipgloss.Width(s)))
}

// Table draws rows under a header with the lipgloss table package. It uses
// the colour choice of out when out is a Writer, and plain text otherwise.
func Table(out io.Writer, header []string, rows [][]string) string {
	r := lipgloss.NewRenderer(io.Discard)
	r.SetColorProfile(termenv.Ascii)
	if w, ok := out.(*Writer); ok {
		r = w.renderer
	}
	t := table.New().
		Border(lipgloss.NormalBorder()).
		BorderStyle(r.NewStyle().Faint(true)).
		Headers(header...).
		Rows(rows...)

	return t.Render() + "\n"
}

// ShortPath shows a path under the home folder as ~/…
func ShortPath(s string) string {
	home, err := os.UserHomeDir()
	if err != nil || home == "" || home == "/" {
		return s
	}

	return strings.ReplaceAll(s, home, "~")
}

// Brief is the short result of a step: the first sentence of its reason, cut
// at 60 characters, with paths under the home folder shortened.
func Brief(reason string) string {
	s := ShortPath(reason)
	for _, sep := range []string{". ", "; "} {
		if i := strings.Index(s, sep); i >= 0 {
			s = s[:i]
		}
	}
	s = strings.TrimSuffix(strings.TrimSpace(s), ".")
	if r := []rune(s); len(r) > 100 {
		s = string(r[:99]) + "…"
	}

	return s
}
