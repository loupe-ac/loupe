package publicapi

import (
	"bytes"
	"os"
	"path/filepath"
	"slices"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/publicapi/gen"
)

func specPath(t *testing.T) string {
	t.Helper()
	if p := os.Getenv("LOUPE_PUBLIC_SPEC"); p != "" {
		return p
	}

	return filepath.Join("..", "..", "..", "openapi", "public.json")
}

func readSpec(t *testing.T) []byte {
	t.Helper()
	data, err := os.ReadFile(specPath(t))
	if err != nil {
		t.Fatalf("read the spec %s: %v\nRun `just cli-test`, which mounts openapi/public.json, or set LOUPE_PUBLIC_SPEC.", specPath(t), err)
	}

	return data
}

func TestTableMatchesTheSpec(t *testing.T) {
	want, err := gen.Generate(readSpec(t))
	if err != nil {
		t.Fatal(err)
	}
	got, err := os.ReadFile("operations_gen.go")
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, want) {
		t.Fatal("operations_gen.go differs from openapi/public.json: run `just cli-generate`")
	}
}

func TestEachOperationHasOneCommandOrAnException(t *testing.T) {
	commands := map[string]int{}
	for _, c := range Commands {
		commands[c.Op]++
	}
	excepted := map[string]string{}
	for _, e := range Exceptions {
		if e.Reason == "" {
			t.Errorf("exception %s has no reason", e.Op)
		}
		if _, dup := excepted[e.Op]; dup {
			t.Errorf("operation %s is excepted twice", e.Op)
		}
		excepted[e.Op] = e.Reason
	}

	for _, op := range Operations {
		n, hasReason := commands[op.ID], excepted[op.ID] != ""
		switch {
		case n == 0 && !hasReason:
			t.Errorf("operation %s (%s %s) has no command: add a line to Commands in commands.go and run `just cli-generate`, or list it in Exceptions with a reason", op.ID, op.Method, op.Path)
		case n > 0 && hasReason:
			t.Errorf("operation %s has a command and an exception", op.ID)
		case n > 1:
			t.Errorf("operation %s has %d commands", op.ID, n)
		}
	}
}

func TestEachCommandNamesAnOperationOfTheTable(t *testing.T) {
	paths := map[string]bool{}
	for _, c := range Commands {
		if _, ok := Lookup(c.Op); !ok {
			t.Errorf("command %v names the unknown operation %s", c.Path, c.Op)
		}
		key := ""
		for _, p := range c.Path {
			key += p + " "
		}
		if paths[key] {
			t.Errorf("command %v is listed twice", c.Path)
		}
		paths[key] = true
	}
	for _, e := range Exceptions {
		if _, ok := Lookup(e.Op); !ok {
			t.Errorf("exception names the unknown operation %s", e.Op)
		}
	}
}

func TestColumnsAndLongTextNamesAreInTheAnswerOrTheBody(t *testing.T) {
	for _, c := range Commands {
		op, ok := Lookup(c.Op)
		if !ok {
			continue
		}
		for _, col := range c.Columns {
			if !slices.Contains(op.AnswerFields, col) {
				t.Errorf("%v: default column %q is no answer field of %s", c.Path, col, op.ID)
			}
		}
		if c.LongText != "" {
			inBody := slices.ContainsFunc(op.Body, func(p Param) bool { return p.Name == c.LongText })
			if !inBody && !slices.Contains(op.AnswerFields, c.LongText) {
				t.Errorf("%v: long text field %q is in neither the body nor the answer of %s", c.Path, c.LongText, op.ID)
			}
		}
		if len(c.Columns) > 0 && !op.TakesFields {
			t.Errorf("%v: default columns need an operation that takes fields", c.Path)
		}
	}
}
