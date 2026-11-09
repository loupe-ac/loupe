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

// NameWidth is the width of the name column. It fits the widest step name of
// init, so the lines of every flush align in one column.
const NameWidth = len("Codex approval")

// Writer wraps the output of init. It holds the steps that were recorded and
// not drawn yet. Any other write flushes them first, so the order on screen
// stays the order of the calls. The folded steps of the whole report wait for
// Finish, which draws them on one line.
type Writer struct {
	out      io.Writer
	verbose  bool
	renderer *lipgloss.Renderer
	pending  []Step
	folded   []string
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

// Write flushes the pending steps, then writes p with each home path shown
// as ~/…
func (w *Writer) Write(p []byte) (int, error) {
	w.Flush()
	if _, err := io.WriteString(w.out, ShortPath(string(p))); err != nil {
		return 0, err
	}

	return len(p), nil
}

// Unwrap draws the pending steps and returns the output under the writer. A
// child process that must see the terminal itself takes this output.
func (w *Writer) Unwrap() io.Writer {
	w.Flush()

	return w.out
}

// Record adds a step to the pending lines.
func (w *Writer) Record(s Step) {
	w.pending = append(w.pending, s)
}

// Flush draws the pending steps. It keeps the folded steps for Finish.
func (w *Writer) Flush() {
	if len(w.pending) == 0 {
		return
	}
	steps := w.pending
	w.pending = nil
	_, _ = io.WriteString(w.out, w.render(steps))
}

// Finish flushes the pending steps, then draws the one dimmed line of the
// steps that were set up already.
func (w *Writer) Finish() {
	w.Flush()
	if len(w.folded) == 0 {
		return
	}
	dim := w.renderer.NewStyle().Faint(true)
	_, _ = io.WriteString(w.out, dim.Render("· Already set up: "+strings.Join(w.folded, ", "))+"\n")
	w.folded = nil
}

func (w *Writer) render(steps []Step) string {
	good := w.renderer.NewStyle().Foreground(lipgloss.Color("2"))
	bad := w.renderer.NewStyle().Foreground(lipgloss.Color("1"))
	warn := w.renderer.NewStyle().Foreground(lipgloss.Color("3"))

	if w.verbose {
		return w.renderVerbose(steps)
	}

	var b strings.Builder
	for _, s := range steps {
		if s.Fold {
			w.folded = append(w.folded, strings.ToLower(s.Name))

			continue
		}
		b.WriteString(mark(s.State, good, bad, warn) + " " + pad(s.Name, NameWidth) + "  " + s.Short + "\n")
	}

	return b.String()
}

// renderVerbose draws each step on its own line, with its full reason and no
// mark, the form that a script reads.
func (w *Writer) renderVerbose(steps []Step) string {
	var b strings.Builder
	for _, s := range steps {
		b.WriteString(s.Name + ": " + s.State + ", " + ShortPath(s.Reason) + ".\n")
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

// ShortPath shows a path under the home folder as ~/… It leaves a path that
// only holds the home path, such as /home/ann2 or /srv/home/ann.
func ShortPath(s string) string {
	home, err := os.UserHomeDir()
	home = strings.TrimSuffix(home, "/")
	if err != nil || home == "" {
		return s
	}

	var b strings.Builder
	rest := 0
	for from := 0; ; {
		i := strings.Index(s[from:], home)
		if i < 0 {
			break
		}
		start, end := from+i, from+i+len(home)
		from = end
		if start > 0 && (isNameChar(s[start-1]) || s[start-1] == '/' || s[start-1] == '.') {
			continue
		}
		if !endsName(s, end) {
			continue
		}
		b.WriteString(s[rest:start] + "~")
		rest = end
	}
	b.WriteString(s[rest:])

	return b.String()
}

// endsName reports whether the home path that stops before s[i] is a whole
// folder name: the text ends, a path or a space follows, or punctuation
// follows that ends the path token. Punctuation that more text follows, as in
// "/home/ann.backup" or "/home/ann:old/x", continues the name.
func endsName(s string, i int) bool {
	if i == len(s) || s[i] == '/' || isSpace(s[i]) {
		return true
	}
	for ; i < len(s) && strings.IndexByte(".,;:!?)]}'\"", s[i]) >= 0; i++ {
	}

	return i == len(s) || isSpace(s[i])
}

func isSpace(c byte) bool {
	return c == ' ' || c == '\t' || c == '\n' || c == '\r'
}

// isNameChar reports whether c can continue a folder name, so the home path
// is only the start of a longer name.
func isNameChar(c byte) bool {
	return c >= 0x80 || c == '-' || c == '_' || c >= '0' && c <= '9' || c >= 'A' && c <= 'Z' || c >= 'a' && c <= 'z'
}

// Brief is the short result of a step: the first sentence of its reason, cut
// at 100 characters, with paths under the home folder shortened.
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
