package cmd

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/publicapi"
)

type seenRequest struct {
	method, path, query string
}

// publicServer answers each request with body and records it.
func publicServer(t *testing.T, body string) (*httptest.Server, *[]seenRequest) {
	t.Helper()
	var mu sync.Mutex
	var seen []seenRequest
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		seen = append(seen, seenRequest{r.Method, r.URL.EscapedPath(), r.URL.RawQuery})
		mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)

	return srv, &seen
}

func runPublicCmd(t *testing.T, server *httptest.Server, args ...string) (stdout, stderr string, err error) {
	t.Helper()
	t.Setenv(apiKeyEnv, "key-1")
	t.Setenv("LOUPE_URL", server.URL)
	t.Setenv(projectEnv, "")
	var out, errOut bytes.Buffer
	root := newRootCmd()
	root.SetOut(&out)
	root.SetErr(&errOut)
	root.SetArgs(args)
	err = root.Execute()

	return out.String(), errOut.String(), err
}

func exitOf(err error) int {
	if err == nil {
		return 0
	}
	if code, ok := ExitCode(err); ok {
		return code
	}

	return 1
}

func TestCardGetPrintsKeyAndValueLinesWithTheBodyLast(t *testing.T) {
	srv, seen := publicServer(t, `{"cardId":"c1","body":"first line\nsecond line","number":42,"title":"A title","status":"next"}`)

	out, _, err := runPublicCmd(t, srv, "card", "get", "42", "--project", "my-project", "--format", "table")
	if err != nil {
		t.Fatal(err)
	}
	if got := (*seen)[0]; got.method != "GET" || got.path != "/api/v1/projects/my-project/cards/42" {
		t.Fatalf("request = %+v", got)
	}
	lines := strings.Split(strings.TrimRight(out, "\n"), "\n")
	if strings.Contains(lines[0], "NAME") || !strings.HasPrefix(lines[0], "cardId") {
		t.Fatalf("one item must print name and value lines with no header:\n%s", out)
	}
	last := strings.Join(lines[len(lines)-3:], "\n")
	if !strings.Contains(last, "body") || !strings.HasSuffix(out, "first line\nsecond line\n") {
		t.Fatalf("the body must come last and whole:\n%s", out)
	}
}

func TestProjectCommandsNeverAskForAProject(t *testing.T) {
	srv, seen := publicServer(t, `{"items":[{"id":"p1","slug":"one","name":"One"},{"id":"p2","slug":"two","name":"Two"}],"next":null}`)

	out, _, err := runPublicCmd(t, srv, "project", "list", "--format", "tsv")
	if err != nil {
		t.Fatal(err)
	}
	if len(*seen) != 1 || (*seen)[0].path != "/api/v1/projects" {
		t.Fatalf("project list must send one request and look for no project: %+v", *seen)
	}
	if want := "p1\tone\tOne\np2\ttwo\tTwo\n"; out != want {
		t.Fatalf("tsv = %q, want %q", out, want)
	}
}

func TestListSendsTheDefaultColumnsOnlyForTableAndTsv(t *testing.T) {
	srv, seen := publicServer(t, `{"items":[{"number":1,"title":"T","status":"next","type":"feature","updatedAt":"2026-11-02T10:00:00+00:00"}],"next":null}`)

	if _, _, err := runPublicCmd(t, srv, "card", "list", "--project", "p", "--format", "tsv"); err != nil {
		t.Fatal(err)
	}
	if _, _, err := runPublicCmd(t, srv, "card", "list", "--project", "p", "--format", "json"); err != nil {
		t.Fatal(err)
	}
	if _, _, err := runPublicCmd(t, srv, "card", "list", "--project", "p", "--format", "tsv", "--fields", "title,number"); err != nil {
		t.Fatal(err)
	}
	want := []string{
		"fields=number%2Ctitle%2Cstatus%2Ctype%2CupdatedAt",
		"",
		"fields=title%2Cnumber",
	}
	for i, w := range want {
		if (*seen)[i].query != w {
			t.Errorf("request %d query = %q, want %q", i, (*seen)[i].query, w)
		}
	}
}

func TestCardListSendsTheStateFilters(t *testing.T) {
	srv, seen := publicServer(t, `{"items":[],"next":null}`)

	if _, _, err := runPublicCmd(t, srv, "card", "list", "--project", "p", "--format", "json", "--state", "working", "--state-code", "run-open"); err != nil {
		t.Fatal(err)
	}
	if got, want := (*seen)[0].query, "state=working&stateCode=run-open"; got != want {
		t.Errorf("query = %q, want %q", got, want)
	}
}

func TestListWithFieldsAllAsksForAndPrintsEveryField(t *testing.T) {
	srv, seen := publicServer(t, `{"items":[{"number":1,"title":"T","body":"text"}],"next":null}`)

	out, _, err := runPublicCmd(t, srv, "card", "list", "--project", "p", "--format", "tsv", "--fields", "all")
	if err != nil {
		t.Fatal(err)
	}
	if out != "1\tT\ttext\n" {
		t.Errorf("out = %q", out)
	}
	if (*seen)[0].query != "fields=all" {
		t.Errorf("query = %q", (*seen)[0].query)
	}
}

func TestListTakesACursorOrANextAddressAndNotesTheNextPage(t *testing.T) {
	srv, seen := publicServer(t, `{"items":[],"next":"https://x.test/api/v1/projects/p/cards?cursor=abc123&limit=1"}`)

	_, errOut, err := runPublicCmd(t, srv, "card", "list", "--project", "p", "--format", "tsv", "--limit", "1",
		"--cursor", "https://other.test/api/v1/projects/p/cards?cursor=zzz")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains((*seen)[0].query, "cursor=zzz") || (*seen)[0].path != "/api/v1/projects/p/cards" {
		t.Fatalf("the request must use only the cursor of the address: %+v", (*seen)[0])
	}
	if !strings.Contains(errOut, "--cursor abc123") {
		t.Fatalf("stderr must hold the next cursor, got %q", errOut)
	}
}

func TestUnknownSubcommandAndBadFlagExitTwo(t *testing.T) {
	srv, seen := publicServer(t, `{}`)

	for _, args := range [][]string{
		{"card", "nope"},
		{"document", "version", "nope"},
		{"card", "get", "42", "--bogus"},
		{"card", "--bogus"},
		{"card", "get"},
		{"card", "get", "1", "2"},
		{"card", "list", "--format", "yaml"},
	} {
		if _, _, err := runPublicCmd(t, srv, args...); exitOf(err) != exitUsage {
			t.Errorf("%v: exit = %d (%v), want %d", args, exitOf(err), err, exitUsage)
		}
	}
	if len(*seen) != 0 {
		t.Fatalf("a usage error must send no request: %+v", *seen)
	}
}

func TestFieldsOfAReadWithoutFieldsAreCheckedBeforeTheSend(t *testing.T) {
	srv, seen := publicServer(t, `{"column":"next","columns":[]}`)

	_, _, err := runPublicCmd(t, srv, "column", "get", "next", "--project", "p", "--fields", "nope")
	if exitOf(err) != exitUsage || len(*seen) != 0 {
		t.Fatalf("a wrong field must exit 2 and send nothing: %v %+v", err, *seen)
	}
	out, _, err := runPublicCmd(t, srv, "column", "get", "next", "--project", "p", "--fields", "column", "--format", "tsv")
	if err != nil {
		t.Fatal(err)
	}
	if out != "next\n" {
		t.Fatalf("one named field of one item must print bare, got %q", out)
	}
}

func TestEachPathValueIsEscaped(t *testing.T) {
	srv, seen := publicServer(t, `{"versionNumber":3,"markdown":"m"}`)

	if _, _, err := runPublicCmd(t, srv, "document", "version", "get", "d/1", "3", "--project", "a b", "--format", "json"); err != nil {
		t.Fatal(err)
	}
	if got := (*seen)[0].path; got != "/api/v1/projects/a%20b/documents/d%2F1/versions/3" {
		t.Fatalf("path = %q", got)
	}
}

func TestGroupsAndCommandsSayBeta(t *testing.T) {
	root := newRootCmd()
	for _, c := range publicapi.Commands {
		leaf, _, err := root.Find(c.Path)
		if err != nil || leaf.Name() != c.Path[len(c.Path)-1] {
			t.Errorf("command %v is missing: %v", c.Path, err)

			continue
		}
		if !strings.Contains(leaf.Long, "beta") {
			t.Errorf("%v help must say beta", c.Path)
		}
		group, _, _ := root.Find(c.Path[:len(c.Path)-1])
		if !strings.Contains(group.Long, "beta") && group.Name() != "project" {
			t.Errorf("group %v help must say beta", c.Path[:len(c.Path)-1])
		}
	}
}

func TestAProjectIsFoundOnlyForACommandThatNeedsOne(t *testing.T) {
	srv, seen := publicServer(t, `{"items":[{"id":"p1"}],"next":null}`)

	if _, _, err := runPublicCmd(t, srv, "card", "list", "--format", "json"); err != nil {
		t.Fatal(err)
	}
	if len(*seen) != 2 || (*seen)[0].path != "/api/v1/projects" || (*seen)[1].path != "/api/v1/projects/p1/cards" {
		t.Fatalf("card list must look up the single project first: %+v", *seen)
	}
}

func TestEmptyListNotesOnStderrOfATerminalOnly(t *testing.T) {
	const empty = `{"items":[],"next":null}`
	base := []string{"card", "list", "--project", "my-project"}

	t.Run("table on a terminal", func(t *testing.T) {
		srv, _ := publicServer(t, empty)
		onTerminal(t, 80)
		out, errOut, err := runPublicCmd(t, srv, base...)
		if err != nil {
			t.Fatal(err)
		}
		if out != "" || errOut != emptyListNote+"\n" {
			t.Fatalf("stdout %q, stderr %q", out, errOut)
		}
	})
	t.Run("table in a pipe", func(t *testing.T) {
		srv, _ := publicServer(t, empty)
		out, errOut, err := runPublicCmd(t, srv, append(base, "--format", "table")...)
		if err != nil || out != "" || errOut != "" {
			t.Fatalf("stdout %q, stderr %q, err %v", out, errOut, err)
		}
	})
	t.Run("tsv on a terminal", func(t *testing.T) {
		srv, _ := publicServer(t, empty)
		onTerminal(t, 80)
		out, errOut, err := runPublicCmd(t, srv, append(base, "--format", "tsv")...)
		if err != nil || out != "" || errOut != "" {
			t.Fatalf("stdout %q, stderr %q, err %v", out, errOut, err)
		}
	})
	t.Run("json on a terminal", func(t *testing.T) {
		srv, _ := publicServer(t, empty)
		onTerminal(t, 80)
		out, errOut, err := runPublicCmd(t, srv, append(base, "--format", "json")...)
		if err != nil || !strings.Contains(out, `"items":[]`) || errOut != "" {
			t.Fatalf("stdout %q, stderr %q, err %v", out, errOut, err)
		}
	})
	t.Run("a list with rows", func(t *testing.T) {
		srv, _ := publicServer(t, `{"items":[{"number":1}],"next":null}`)
		onTerminal(t, 80)
		_, errOut, err := runPublicCmd(t, srv, base...)
		if err != nil || errOut != "" {
			t.Fatalf("stderr %q, err %v", errOut, err)
		}
	})
}

func TestMetricQueryFormulaFormatFlagSendsTheFormatParameter(t *testing.T) {
	srv, seen := publicServer(t, `{}`)

	if _, _, err := runPublicCmd(t, srv, "metric", "query", "--project", "p", "--format", "json", "--unit", "run", "--rows", "A:cost:sum", "--formula", "A", "--formula-format", "money"); err != nil {
		t.Fatal(err)
	}
	if got, want := (*seen)[0].query, "format=money&formula=A&rows=A%3Acost%3Asum&unit=run"; got != want {
		t.Errorf("query = %q, want %q", got, want)
	}
}
