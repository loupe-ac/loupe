package output

import (
	"bytes"
	"strings"
	"testing"
	"time"
)

const card = `{"number":42,"title":"Fix\tit","status":"next","body":"one line","related":[{"a":1}],"note":"a\nb","draft":false,"gone":null}`

func render(t *testing.T, f Format, body string, o Options) string {
	t.Helper()
	var b bytes.Buffer
	if err := Write(&b, f, []byte(body), o); err != nil {
		t.Fatal(err)
	}
	return b.String()
}

func TestParseAndDefault(t *testing.T) {
	if f, err := Parse("toon"); err != nil || f != TOON {
		t.Fatalf("parse: %v %v", f, err)
	}
	if _, err := Parse("xml"); err == nil {
		t.Fatal("xml must fail")
	}
	if Default(true) != Table || Default(false) != TSV {
		t.Fatal("default format")
	}
}

func TestTSVItemOrderAndEscape(t *testing.T) {
	got := render(t, TSV, card, Options{LongText: "body"})
	want := "number\t42\ntitle\tFix\\tit\nstatus\tnext\ndraft\tfalse\ngone\t\nrelated\t[{\"a\":1}]\nnote\ta\\nb\nbody\tone line\n"
	if got != want {
		t.Fatalf("got\n%q\nwant\n%q", got, want)
	}
}

func TestTSVSingleNamedFieldIsExact(t *testing.T) {
	got := render(t, TSV, card, Options{Fields: []string{"note"}})
	if got != "a\nb\n" {
		t.Fatalf("got %q", got)
	}
	got = render(t, TSV, card, Options{Fields: []string{"note", "number"}})
	if got != "number\t42\nnote\ta\\nb\n" {
		t.Fatalf("two names stay escaped and ordered: %q", got)
	}
}

func TestTSVListUsesColumnsAndKeepsOneLine(t *testing.T) {
	body := `{"items":[{"number":1,"title":"a\nb","x":9},{"number":2,"title":"c"}],"next":null}`
	if got := render(t, TSV, body, Options{Columns: []string{"number", "title"}}); got != "1\ta\\nb\n2\tc\n" {
		t.Fatalf("got %q", got)
	}
	if got := render(t, TSV, body, Options{Fields: []string{"title", "number"}, Columns: []string{"number"}}); got != "a\\nb\t1\nc\t2\n" {
		t.Fatalf("caller order: %q", got)
	}
	if got := render(t, TSV, body, Options{Fields: []string{"title"}}); got != "a\\nb\nc\n" {
		t.Fatalf("one named field in a list stays escaped: %q", got)
	}
	if got := render(t, TSV, `{"items":[],"next":null}`, Options{}); got != "" {
		t.Fatalf("empty list prints nothing: %q", got)
	}
}

func TestTableItemBodyLastAndWhole(t *testing.T) {
	body := `{"number":42,"title":"T","body":"line1\nline2\ttab\u001b[31mred","url":"u"}`
	got := render(t, Table, body, Options{LongText: "body"})
	want := "number  42\ntitle   T\nurl     u\n\nbody\nline1\nline2\ttab�[31mred\n"
	if got != want {
		t.Fatalf("got\n%q\nwant\n%q", got, want)
	}
	if strings.Contains(got, "\x1b") {
		t.Fatal("escape sequence reached the table")
	}
}

func TestTableItemOneLineBodyStillLast(t *testing.T) {
	got := render(t, Table, `{"body":"hi","number":1}`, Options{LongText: "body"})
	if got != "number  1\n\nbody\nhi\n" {
		t.Fatalf("got %q", got)
	}
}

func TestTableItemKeepsTrailingNewlinesOfLongText(t *testing.T) {
	cases := map[string]struct{ body, want string }{
		"one kept":        {`{"number":1,"body":"a\n"}`, "number  1\n\nbody\na\n"},
		"several kept":    {`{"number":1,"body":"a\n\n\n"}`, "number  1\n\nbody\na\n\n\n"},
		"only newlines":   {`{"number":1,"body":"\n\n"}`, "number  1\n\nbody\n\n\n"},
		"none gets one":   {`{"number":1,"body":"a"}`, "number  1\n\nbody\na\n"},
		"empty gets one":  {`{"number":1,"body":""}`, "number  1\n\nbody\n\n"},
		"not the last":    {`{"note":"a\n\n","body":"b\n"}`, "note\na\n\n\nbody\nb\n"},
		"multi-line note": {`{"note":"a\nb\n\n","number":1}`, "number  1\n\nnote\na\nb\n\n"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			got := render(t, Table, c.body, Options{LongText: "body"})
			if got != c.want {
				t.Fatalf("got\n%q\nwant\n%q", got, c.want)
			}
		})
	}
}

func TestTableItemCutsValuesToWidth(t *testing.T) {
	got := render(t, Table, `{"title":"`+strings.Repeat("x", 50)+`"}`, Options{Width: 20})
	if line := strings.TrimSuffix(got, "\n"); len([]rune(line)) != 20 || !strings.HasSuffix(line, "…") {
		t.Fatalf("got %q", got)
	}
}

func TestTableListKeepsHeaderForOneRow(t *testing.T) {
	body := `{"items":[{"number":1,"title":"a","updatedAt":"2026-11-02T10:00:00Z"}],"next":null}`
	now := time.Date(2026, 11, 2, 12, 30, 0, 0, time.UTC)
	got := render(t, Table, body, Options{
		Columns:    []string{"number", "title", "updatedAt"},
		TimeFields: []string{"updatedAt"},
		Now:        now,
	})
	want := "NUMBER  TITLE  UPDATEDAT\n1       a      2h ago\n"
	if got != want {
		t.Fatalf("got %q want %q", got, want)
	}
}

func TestTableListNarrowsWidestColumnAndCleansCells(t *testing.T) {
	body := `{"items":[{"a":"` + strings.Repeat("y", 40) + `","b":"x\u001b[0m"}]}`
	got := render(t, Table, body, Options{Columns: []string{"a", "b"}, Width: 20})
	for _, line := range strings.Split(strings.TrimSuffix(got, "\n"), "\n") {
		if n := len([]rune(line)); n > 20 {
			t.Fatalf("line of %d runes: %q", n, line)
		}
	}
	if strings.Contains(got, "\x1b") {
		t.Fatal("escape sequence reached the table")
	}
}

func TestJSONPrintsBytesOrCutsFields(t *testing.T) {
	if got := render(t, JSON, card, Options{}); got != card+"\n" {
		t.Fatalf("got %q", got)
	}
	got := render(t, JSON, `{"items":[{"b":1,"a":2.50}],"next":"n"}`, Options{Fields: []string{"a"}})
	want := "{\n  \"items\": [\n    {\n      \"a\": 2.50\n    }\n  ],\n  \"next\": \"n\"\n}\n"
	if got != want {
		t.Fatalf("got\n%s\nwant\n%s", got, want)
	}
	if got := render(t, JSON, card, Options{Fields: []string{"all"}}); got != card+"\n" {
		t.Fatalf("all must not cut: %q", got)
	}
}

func TestTOONKeepsKeyOrder(t *testing.T) {
	got := render(t, TOON, `{"zeta":1,"alpha":"x","list":[1,2]}`, Options{})
	want := "zeta: 1\nalpha: x\nlist[2]: 1,2\n"
	if got != want {
		t.Fatalf("got %q want %q", got, want)
	}
}

func TestTOONCutsFields(t *testing.T) {
	got := render(t, TOON, `{"items":[{"n":1,"t":"a"},{"n":2,"t":"b"}],"next":null}`, Options{Fields: []string{"t"}})
	if got != "items[2]{t}:\n  a\n  b\nnext: null\n" {
		t.Fatalf("got %q", got)
	}
}
