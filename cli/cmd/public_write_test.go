package cmd

import (
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
)

type seenWrite struct {
	method, path, query, body, key string
}

// writeServer answers each request with status and body, and records it.
func writeServer(t *testing.T, status int, answer string) (*httptest.Server, *[]seenWrite) {
	t.Helper()
	var mu sync.Mutex
	var seen []seenWrite
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		data, _ := io.ReadAll(r.Body)
		mu.Lock()
		seen = append(seen, seenWrite{r.Method, r.URL.EscapedPath(), r.URL.RawQuery, string(data), r.Header.Get("Idempotency-Key")})
		mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		_, _ = w.Write([]byte(answer))
	}))
	t.Cleanup(srv.Close)

	return srv, &seen
}

func TestWriteFlagsBuildASortedJSONBodyByType(t *testing.T) {
	srv, seen := writeServer(t, 201, `{"cardId":"c1","url":"https://app/cards/7"}`)

	out, errOut, err := runPublicCmd(t, srv, "card", "create", "--project", "p1",
		"--title", "A title", "--body", "Text", "--type", "feature",
		"--document-ids", "d1,d2", "--lane-enabled=false",
		"--related-cards", `[{"cardId":"x","kind":"blocked-by"}]`)
	if err != nil {
		t.Fatal(err)
	}
	got := (*seen)[0]
	want := `{"body":"Text","documentIds":["d1","d2"],"laneEnabled":false,"relatedCards":[{"cardId":"x","kind":"blocked-by"}],"title":"A title","type":"feature"}`
	if got.method != "POST" || got.path != "/api/v1/projects/p1/cards" || got.body != want {
		t.Fatalf("request = %+v\nwant body %s", got, want)
	}
	if out != "https://app/cards/7\n" || !strings.Contains(errOut, "Done:") {
		t.Fatalf("stdout must hold the url and stderr a note:\nout=%q\nerr=%q", out, errOut)
	}
}

func TestInputAndFlagsGiveTheSameBodyAndTheFlagsWin(t *testing.T) {
	srv, seen := writeServer(t, 201, `{}`)
	file := filepath.Join(t.TempDir(), "in.json")
	if err := os.WriteFile(file, []byte(`{"type":"bug","title":"From input","body":"B"}`), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, _, err := runPublicCmd(t, srv, "card", "create", "--project", "p1", "--input", file, "--title", "From flag"); err != nil {
		t.Fatal(err)
	}
	if _, _, err := runPublicCmd(t, srv, "card", "create", "--project", "p1", "--title", "From flag", "--type", "bug", "--body", "B"); err != nil {
		t.Fatal(err)
	}
	if (*seen)[0].body != (*seen)[1].body || !strings.Contains((*seen)[0].body, "From flag") {
		t.Fatalf("bodies differ: %q and %q", (*seen)[0].body, (*seen)[1].body)
	}
}

func TestBodyFileFillsTheLongTextFieldAndRefusesTheSameFlag(t *testing.T) {
	srv, seen := writeServer(t, 201, `{}`)
	file := filepath.Join(t.TempDir(), "body.md")
	if err := os.WriteFile(file, []byte("line one\nline two\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, _, err := runPublicCmd(t, srv, "card", "create", "--project", "p1", "--title", "T", "--type", "feature", "--body-file", file); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains((*seen)[0].body, `"body":"line one\nline two\n"`) {
		t.Fatalf("body = %s", (*seen)[0].body)
	}

	_, _, err := runPublicCmd(t, srv, "card", "create", "--project", "p1", "--title", "T", "--type", "feature", "--body", "x", "--body-file", file)
	if exitOf(err) != 2 || len(*seen) != 1 {
		t.Fatalf("both flags must exit 2 and send nothing: err=%v sent=%d", err, len(*seen))
	}
}

func TestAMissingRequiredValueExitsTwoAndNamesTheFlag(t *testing.T) {
	srv, seen := writeServer(t, 201, `{}`)

	_, _, err := runPublicCmd(t, srv, "card", "create", "--project", "p1", "--title", "T")
	if exitOf(err) != 2 || !strings.Contains(err.Error(), "--body") || len(*seen) != 0 {
		t.Fatalf("err=%v sent=%d", err, len(*seen))
	}

	_, _, err = runPublicCmd(t, srv, "document", "decision-clear", "d1", "dec1", "--project", "p1")
	if exitOf(err) != 2 || !strings.Contains(err.Error(), "--version-number") {
		t.Fatalf("a required query value must be named: %v", err)
	}
}

func TestANullableFieldTakesTheWordNull(t *testing.T) {
	srv, seen := writeServer(t, 201, `{}`)

	if _, _, err := runPublicCmd(t, srv, "document", "verdict", "d1", "--project", "p1",
		"--version-number", "3", "--verdict", "approved", "--expected-verdict-id", "null"); err != nil {
		t.Fatal(err)
	}
	if want := `{"expectedVerdictId":null,"verdict":"approved","versionNumber":3}`; (*seen)[0].body != want {
		t.Fatalf("body = %s, want %s", (*seen)[0].body, want)
	}
}

func TestWriteOutputFollowsTheFormat(t *testing.T) {
	srv, _ := writeServer(t, 200, `{"cardId":"c1","number":7,"url":"https://app/cards/7"}`)

	out, _, err := runPublicCmd(t, srv, "card", "hold", "7", "--project", "p1", "--format", "json")
	if err != nil || !strings.Contains(out, `"cardId"`) {
		t.Fatalf("--format json must print the answer: err=%v out=%q", err, out)
	}

	out, _, err = runPublicCmd(t, srv, "card", "hold", "7", "--project", "p1", "--format", "table")
	if err != nil || !strings.HasPrefix(out, "cardId") {
		t.Fatalf("--format table must print the lines of one item: err=%v out=%q", err, out)
	}

	empty, _ := writeServer(t, 200, `{"id":"x"}`)
	out, _, err = runPublicCmd(t, empty, "card", "hold", "7", "--project", "p1")
	if err != nil || out != "" {
		t.Fatalf("an answer with no url leaves stdout empty: err=%v out=%q", err, out)
	}
}

func TestWriteFieldsAreCheckedBeforeTheSend(t *testing.T) {
	srv, seen := writeServer(t, 200, `{}`)

	_, _, err := runPublicCmd(t, srv, "card", "hold", "7", "--project", "p1", "--fields", "nope")
	if exitOf(err) != 2 || len(*seen) != 0 {
		t.Fatalf("a wrong field must exit 2 and send nothing: err=%v sent=%d", err, len(*seen))
	}
}

func TestIdempotencyKeyFlagBeatsTheSeedAndTheSeedIsStable(t *testing.T) {
	srv, seen := writeServer(t, 201, `{}`)
	args := []string{"card", "create", "--project", "p1", "--title", "T", "--type", "feature", "--body", "B"}

	t.Setenv(seedEnv, "")
	if _, _, err := runPublicCmd(t, srv, args...); err != nil {
		t.Fatal(err)
	}
	if (*seen)[0].key != "" {
		t.Fatalf("no seed and no flag must send no key, got %q", (*seen)[0].key)
	}

	if _, _, err := runPublicCmd(t, srv, append(args, "--idempotency-key", "mine")...); err != nil {
		t.Fatal(err)
	}
	if (*seen)[1].key != "mine" {
		t.Fatalf("key = %q", (*seen)[1].key)
	}

	runSeeded := func(extra ...string) string {
		t.Helper()
		t.Setenv(seedEnv, "seed-1")
		if _, _, err := runPublicCmd(t, srv, append(args, extra...)...); err != nil {
			t.Fatal(err)
		}

		return (*seen)[len(*seen)-1].key
	}
	a, b := runSeeded(), runSeeded()
	if !strings.HasPrefix(a, "loupe-seed-") || a != b {
		t.Fatalf("the same seed and request must give the same key: %q %q", a, b)
	}
	if c := runSeeded("--title", "Other"); c == a {
		t.Fatal("another body must give another key")
	}
	if got := runSeeded("--idempotency-key", "mine"); got != "mine" {
		t.Fatalf("the flag must win over the seed, got %q", got)
	}
}

func TestOnlyAnIdempotentOperationHasTheKeyFlag(t *testing.T) {
	srv, _ := writeServer(t, 200, `{}`)

	_, _, err := runPublicCmd(t, srv, "card", "hold", "7", "--project", "p1", "--idempotency-key", "k")
	if exitOf(err) != 2 {
		t.Fatalf("holdCard names no Idempotency-Key header, so the flag must be unknown: %v", err)
	}
}

func TestColumnDeleteNeedsYesWithoutATerminal(t *testing.T) {
	srv, seen := writeServer(t, 200, `{}`)

	_, _, err := runPublicCmd(t, srv, "column", "delete", "old", "--project", "p1")
	if exitOf(err) != 2 || len(*seen) != 0 {
		t.Fatalf("no terminal and no --yes must exit 2 and send nothing: err=%v sent=%d", err, len(*seen))
	}

	if _, _, err := runPublicCmd(t, srv, "column", "delete", "old", "--project", "p1", "--yes", "--target-column", "next"); err != nil {
		t.Fatal(err)
	}
	if got := (*seen)[0]; got.method != "DELETE" || got.path != "/api/v1/projects/p1/board/columns/old" || got.query != "targetColumn=next" {
		t.Fatalf("request = %+v", got)
	}
}

func TestColumnDeleteTreatsAPipedStdoutAsNoTerminal(t *testing.T) {
	srv, seen := writeServer(t, 200, `{}`)
	oldReader, oldWriter := isTerminalReader, isTerminalWriter
	t.Cleanup(func() { isTerminalReader, isTerminalWriter = oldReader, oldWriter })
	var stdout, stderr strings.Builder
	isTerminalReader = func(io.Reader) bool { return true }
	isTerminalWriter = func(w io.Writer) bool { return w == io.Writer(&stderr) }

	t.Setenv(apiKeyEnv, "key-1")
	t.Setenv("LOUPE_URL", srv.URL)
	root := newRootCmd()
	root.SetIn(strings.NewReader("y\n"))
	root.SetOut(&stdout)
	root.SetErr(&stderr)
	root.SetArgs([]string{"column", "delete", "old", "--project", "p1"})
	err := root.Execute()
	if exitOf(err) != 2 || len(*seen) != 0 || strings.Contains(stderr.String(), "[y/N]") {
		t.Fatalf("stdin and stderr as terminals with stdout in a pipe must exit 2 with no prompt: err=%v sent=%d", err, len(*seen))
	}
}

func TestColumnDeleteAsksWhenAllThreeAreTerminals(t *testing.T) {
	oldReader, oldWriter := isTerminalReader, isTerminalWriter
	t.Cleanup(func() { isTerminalReader, isTerminalWriter = oldReader, oldWriter })
	isTerminalReader = func(io.Reader) bool { return true }
	isTerminalWriter = func(io.Writer) bool { return true }

	for answer, sent := range map[string]int{"y\n": 1, "n\n": 0, "\n": 0} {
		srv, seen := writeServer(t, 200, `{}`)
		t.Setenv(apiKeyEnv, "key-1")
		t.Setenv("LOUPE_URL", srv.URL)
		root := newRootCmd()
		var errOut strings.Builder
		root.SetIn(strings.NewReader(answer))
		root.SetOut(io.Discard)
		root.SetErr(&errOut)
		root.SetArgs([]string{"column", "delete", "old", "--project", "p1"})
		_ = root.Execute()
		if len(*seen) != sent || !strings.Contains(errOut.String(), "[y/N]") {
			t.Fatalf("answer %q: sent=%d stderr=%q", answer, len(*seen), errOut.String())
		}
	}
}

func TestAnInputThatIsNoObjectExitsTwo(t *testing.T) {
	srv, seen := writeServer(t, 201, `{}`)
	file := filepath.Join(t.TempDir(), "in.json")
	if err := os.WriteFile(file, []byte(`["a"]`), 0o600); err != nil {
		t.Fatal(err)
	}

	_, _, err := runPublicCmd(t, srv, "card", "create", "--project", "p1", "--input", file)
	if exitOf(err) != 2 || len(*seen) != 0 {
		t.Fatalf("err=%v sent=%d", err, len(*seen))
	}
}
