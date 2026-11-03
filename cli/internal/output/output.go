// Package output prints a public API answer as a table, tsv, json or toon.
package output

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/toon-format/toon-go"
)

type Format string

const (
	Table Format = "table"
	TSV   Format = "tsv"
	JSON  Format = "json"
	TOON  Format = "toon"
)

// Parse reads the value of --format.
func Parse(s string) (Format, error) {
	switch f := Format(s); f {
	case Table, TSV, JSON, TOON:
		return f, nil
	}
	return "", fmt.Errorf("unknown format %q: use table, tsv, json or toon", s)
}

// Default is a table on a terminal and tsv in a pipe.
func Default(stdoutIsTerminal bool) Format {
	if stdoutIsTerminal {
		return Table
	}
	return TSV
}

type Options struct {
	// Fields are the names the caller asked for, in the caller's order.
	// Empty means no cut. A list that holds "all" means no cut as well.
	Fields []string
	// Columns are the default columns of a list in table and tsv.
	Columns []string
	// LongText names the field that ends one item.
	LongText string
	// TimeFields name the fields that a table shows as "2h ago".
	TimeFields []string
	// Width cuts table cells. Zero means no cut.
	Width int
	// Now is the clock of "2h ago". Zero means time.Now.
	Now time.Time
}

// Write prints one API answer in the chosen format.
func Write(w io.Writer, f Format, body []byte, o Options) error {
	switch f {
	case JSON:
		return writeJSON(w, body, o)
	case TOON:
		return writeTOON(w, body, o)
	case Table:
		return writeText(w, body, o, true)
	case TSV:
		return writeText(w, body, o, false)
	}
	return fmt.Errorf("unknown format %q", f)
}

func (o Options) names() []string {
	for _, n := range o.Fields {
		if n == "all" {
			return nil
		}
	}
	return o.Fields
}

// cut keeps the named keys of an item, or of each row of a list.
func cut(v any, names []string) any {
	obj, ok := asObject(v)
	if !ok || len(names) == 0 {
		return v
	}
	if items, ok := listItems(obj); ok {
		rows := make([]any, len(items))
		for i, row := range items {
			rows[i] = keep(row, names)
		}
		fields := make([]toon.Field, len(obj.Fields))
		for i, f := range obj.Fields {
			fields[i] = f
			if f.Key == "items" {
				fields[i].Value = rows
			}
		}
		return toon.NewObject(fields...)
	}
	return keep(obj, names)
}

func keep(v any, names []string) any {
	obj, ok := asObject(v)
	if !ok {
		return v
	}
	var fields []toon.Field
	for _, n := range names {
		if val, ok := lookup(obj, n); ok {
			fields = append(fields, toon.Field{Key: n, Value: val})
		}
	}
	return toon.NewObject(fields...)
}

func lookup(obj toon.Object, key string) (any, bool) {
	for _, f := range obj.Fields {
		if f.Key == key {
			return f.Value, true
		}
	}
	return nil, false
}

func listItems(obj toon.Object) ([]any, bool) {
	v, ok := lookup(obj, "items")
	if !ok {
		return nil, false
	}
	items, ok := v.([]any)
	return items, ok
}

func writeJSON(w io.Writer, body []byte, o Options) error {
	names := o.names()
	if len(names) == 0 {
		if _, err := w.Write(body); err != nil {
			return err
		}
		if !bytes.HasSuffix(body, []byte("\n")) {
			_, err := io.WriteString(w, "\n")
			return err
		}
		return nil
	}
	v, err := decode(body)
	if err != nil {
		return err
	}
	var out bytes.Buffer
	if err := json.Indent(&out, []byte(compact(cut(v, names))), "", "  "); err != nil {
		return err
	}
	out.WriteByte('\n')
	_, err = w.Write(out.Bytes())
	return err
}

func writeTOON(w io.Writer, body []byte, o Options) error {
	v, err := decode(body)
	if err != nil {
		return err
	}
	s, err := toon.MarshalString(toToon(cut(v, o.names())))
	if err != nil {
		return err
	}
	_, err = io.WriteString(w, strings.TrimRight(s, "\n")+"\n")
	return err
}

func writeText(w io.Writer, body []byte, o Options, table bool) error {
	v, err := decode(body)
	if err != nil {
		return err
	}
	obj, ok := asObject(v)
	if !ok {
		return fmt.Errorf("the answer is not a JSON object")
	}
	var out bytes.Buffer
	if items, isList := listItems(obj); isList {
		cols := o.names()
		if len(cols) == 0 {
			cols = o.Columns
		}
		if len(cols) == 0 && len(items) > 0 {
			if first, ok := asObject(items[0]); ok {
				for _, f := range first.Fields {
					cols = append(cols, f.Key)
				}
			}
		}
		if table {
			tableList(&out, items, cols, o)
		} else {
			tsvList(&out, items, cols)
		}
	} else if table {
		tableItem(&out, obj, o)
	} else {
		tsvItem(&out, obj, o)
	}
	_, err = w.Write(out.Bytes())
	return err
}

// text is the plain value of a scalar. An object or a list is compact JSON.
func text(v any) string {
	switch t := v.(type) {
	case nil:
		return ""
	case string:
		return t
	case json.Number:
		return t.String()
	case bool:
		if t {
			return "true"
		}
		return "false"
	}
	return compact(v)
}

var tsvEscaper = strings.NewReplacer(`\`, `\\`, "\t", `\t`, "\n", `\n`, "\r", `\r`)

func tsvList(out *bytes.Buffer, items []any, cols []string) {
	for _, row := range items {
		obj, _ := asObject(row)
		cells := make([]string, len(cols))
		for i, c := range cols {
			val, _ := lookup(obj, c)
			cells[i] = tsvEscaper.Replace(text(val))
		}
		out.WriteString(strings.Join(cells, "\t"))
		out.WriteByte('\n')
	}
}

func tsvItem(out *bytes.Buffer, obj toon.Object, o Options) {
	names := o.names()
	if len(names) == 1 {
		val, _ := lookup(obj, names[0])
		out.WriteString(text(val))
		out.WriteByte('\n')
		return
	}
	for _, f := range order(obj, names, o.LongText) {
		out.WriteString(tsvEscaper.Replace(f.Key))
		out.WriteByte('\t')
		out.WriteString(tsvEscaper.Replace(text(f.Value)))
		out.WriteByte('\n')
	}
}

// order puts an item in the order of the design: plain values, then objects
// and lists, then other long text, then the LongText field. The API order
// holds inside each group.
func order(obj toon.Object, names []string, longText string) []toon.Field {
	var groups [4][]toon.Field
	for _, f := range obj.Fields {
		if len(names) > 0 && !contains(names, f.Key) {
			continue
		}
		s, isString := f.Value.(string)
		switch {
		case f.Key == longText:
			groups[3] = append(groups[3], f)
		case isString && strings.Contains(s, "\n"):
			groups[2] = append(groups[2], f)
		case isString:
			groups[0] = append(groups[0], f)
		default:
			switch f.Value.(type) {
			case toon.Object, []any:
				groups[1] = append(groups[1], f)
			default:
				groups[0] = append(groups[0], f)
			}
		}
	}
	var all []toon.Field
	for _, g := range groups {
		all = append(all, g...)
	}
	return all
}

func contains(list []string, s string) bool {
	for _, e := range list {
		if e == s {
			return true
		}
	}
	return false
}

// clean replaces each control character, so a stored escape sequence
// never reaches a terminal. keepLayout leaves newline and tab alone.
func clean(s string, keepLayout bool) string {
	return strings.Map(func(r rune) rune {
		if keepLayout && (r == '\n' || r == '\t') {
			return r
		}
		if r < 0x20 || r == 0x7f || (r >= 0x80 && r < 0xa0) {
			return '�'
		}
		return r
	}, s)
}

func (o Options) now() time.Time {
	if o.Now.IsZero() {
		return time.Now()
	}
	return o.Now
}

// cell is one table cell: one line, control characters replaced.
func cell(key string, v any, o Options) string {
	s := text(v)
	if str, ok := v.(string); ok && contains(o.TimeFields, key) {
		if t, err := time.Parse(time.RFC3339, str); err == nil {
			s = ago(o.now(), t)
		}
	}
	return clean(s, false)
}

func ago(now, t time.Time) string {
	d := now.Sub(t)
	switch {
	case d < 0:
		return t.UTC().Format("2006-01-02")
	case d < time.Minute:
		return "just now"
	case d < time.Hour:
		return fmt.Sprintf("%dm ago", int(d/time.Minute))
	case d < 24*time.Hour:
		return fmt.Sprintf("%dh ago", int(d/time.Hour))
	case d < 60*24*time.Hour:
		return fmt.Sprintf("%dd ago", int(d/(24*time.Hour)))
	}
	return t.UTC().Format("2006-01-02")
}

func truncate(s string, width int) string {
	if width <= 0 || utf8.RuneCountInString(s) <= width {
		return s
	}
	if width == 1 {
		return "…"
	}
	r := []rune(s)
	return string(r[:width-1]) + "…"
}

func pad(s string, width int) string {
	if n := utf8.RuneCountInString(s); n < width {
		return s + strings.Repeat(" ", width-n)
	}
	return s
}

const columnGap = 2

func tableList(out *bytes.Buffer, items []any, cols []string, o Options) {
	if len(items) == 0 {
		return
	}
	header := make([]string, len(cols))
	for i, c := range cols {
		header[i] = clean(strings.ToUpper(c), false)
	}
	rows := [][]string{header}
	for _, row := range items {
		obj, _ := asObject(row)
		cells := make([]string, len(cols))
		for i, c := range cols {
			val, _ := lookup(obj, c)
			cells[i] = cell(c, val, o)
		}
		rows = append(rows, cells)
	}
	widths := make([]int, len(cols))
	for _, r := range rows {
		for i, c := range r {
			widths[i] = max(widths[i], utf8.RuneCountInString(c))
		}
	}
	fit(widths, o.Width)
	for _, r := range rows {
		var line strings.Builder
		for i, c := range r {
			c = truncate(c, widths[i])
			if i < len(r)-1 {
				c = pad(c, widths[i]) + strings.Repeat(" ", columnGap)
			}
			line.WriteString(c)
		}
		out.WriteString(strings.TrimRight(line.String(), " "))
		out.WriteByte('\n')
	}
}

// fit narrows the widest column until the row fits the width.
func fit(widths []int, width int) {
	if width <= 0 {
		return
	}
	const floor = 4
	for {
		total := columnGap * (len(widths) - 1)
		widest := 0
		for i, w := range widths {
			total += w
			if w > widths[widest] {
				widest = i
			}
		}
		if total <= width || widths[widest] <= floor {
			return
		}
		widths[widest]--
	}
}

func tableItem(out *bytes.Buffer, obj toon.Object, o Options) {
	fields := order(obj, o.names(), o.LongText)
	nameWidth := 0
	for _, f := range fields {
		nameWidth = max(nameWidth, utf8.RuneCountInString(clean(f.Key, false)))
	}
	var long []toon.Field
	for _, f := range fields {
		if s, ok := f.Value.(string); ok && strings.Contains(s, "\n") || f.Key == o.LongText {
			long = append(long, f)
			continue
		}
		line := pad(clean(f.Key, false), nameWidth) + strings.Repeat(" ", columnGap)
		value := cell(f.Key, f.Value, o)
		if o.Width > 0 {
			value = truncate(value, max(o.Width-utf8.RuneCountInString(line), 1))
		}
		out.WriteString(strings.TrimRight(line+value, " "))
		out.WriteByte('\n')
	}
	for _, f := range long {
		if out.Len() > 0 {
			out.WriteByte('\n')
		}
		out.WriteString(clean(f.Key, false))
		out.WriteByte('\n')
		value := clean(text(f.Value), true)
		out.WriteString(value)
		if !strings.HasSuffix(value, "\n") {
			out.WriteByte('\n')
		}
	}
}
