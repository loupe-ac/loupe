package cmd

import (
	"bytes"
	"io"
	"mime"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
)

type seenUpload struct {
	method, path, key, contentType, session string
	body                                    []byte
}

func uploadServer(t *testing.T, status int, answer string) (*httptest.Server, *[]seenUpload) {
	t.Helper()
	var mu sync.Mutex
	var seen []seenUpload
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		data, _ := io.ReadAll(r.Body)
		mu.Lock()
		seen = append(seen, seenUpload{r.Method, r.URL.EscapedPath(), r.Header.Get("Idempotency-Key"), r.Header.Get("Content-Type"), r.Header.Get("X-Loupe-Session"), data})
		mu.Unlock()
		if strings.Contains(r.URL.Path, "/cards/") {
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"cardId":"0192f3a1-0000-7000-8000-000000000001"}`))

			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		_, _ = w.Write([]byte(answer))
	}))
	t.Cleanup(srv.Close)

	return srv, &seen
}

func parts(t *testing.T, u seenUpload) (fields map[string][]string, files []string) {
	t.Helper()
	_, params, err := mime.ParseMediaType(u.contentType)
	if err != nil {
		t.Fatal(err)
	}
	fields = map[string][]string{}
	r := multipart.NewReader(bytes.NewReader(u.body), params["boundary"])
	for {
		p, err := r.NextPart()
		if err == io.EOF {
			return fields, files
		}
		if err != nil {
			t.Fatal(err)
		}
		data, _ := io.ReadAll(p)
		if p.FormName() == "files[]" {
			files = append(files, p.Header.Get("Content-Type")+"|"+string(data))

			continue
		}
		fields[p.FormName()] = append(fields[p.FormName()], string(data))
	}
}

func TestArtifactCreateSendsOneMultipartRequestAndNoSession(t *testing.T) {
	srv, seen := uploadServer(t, 201, `{"artifactId":"a1","versionNumber":1,"url":"https://app/a1","versionUrl":"https://app/a1/v1"}`)
	t.Setenv("LOUPE_SESSION_ID", "sess-1")
	dir := t.TempDir()
	write(t, filepath.Join(dir, "index.html"), "<h1>Hi</h1>")
	write(t, filepath.Join(dir, "css", "app.css"), "a{}")

	out, _, err := runPublicCmd(t, srv, "artifact", "create", dir, "--project", "p1", "--name", "Mock", "--notes", "first", "--card", "7")
	if err != nil {
		t.Fatal(err)
	}
	if out != "https://app/a1\n" {
		t.Fatalf("stdout = %q", out)
	}
	if len(*seen) != 2 || (*seen)[0].path != "/api/v1/projects/p1/cards/7" {
		t.Fatalf("a card number needs one read first: %+v", *seen)
	}
	got := (*seen)[1]
	if got.method != "POST" || got.path != "/api/v1/projects/p1/artifacts" || got.session != "" {
		t.Fatalf("request = %+v", got)
	}
	fields, files := parts(t, got)
	if fields["name"][0] != "Mock" || fields["notes"][0] != "first" || fields["card"][0] != "0192f3a1-0000-7000-8000-000000000001" {
		t.Fatalf("fields = %v", fields)
	}
	if strings.Join(fields["paths[]"], ",") != "css/app.css,index.html" || len(files) != 2 || files[1] != "text/html; charset=utf-8|<h1>Hi</h1>" {
		t.Fatalf("paths = %v files = %v", fields["paths[]"], files)
	}
}

func TestArtifactVersionCreateTakesTheArtifactAndAnUuidCard(t *testing.T) {
	srv, seen := uploadServer(t, 201, `{"url":"https://app/a1"}`)
	file := filepath.Join(t.TempDir(), "page.html")
	write(t, file, "x")

	_, _, err := runPublicCmd(t, srv, "artifact", "version", "create", "a1", file, "--project", "p1", "--card", "0192f3a1-0000-7000-8000-000000000009")
	if err != nil {
		t.Fatal(err)
	}
	if len(*seen) != 1 || (*seen)[0].path != "/api/v1/projects/p1/artifacts/a1/versions" {
		t.Fatalf("seen = %+v", *seen)
	}
	fields, _ := parts(t, (*seen)[0])
	if fields["card"][0] != "0192f3a1-0000-7000-8000-000000000009" || fields["name"] != nil {
		t.Fatalf("fields = %v", fields)
	}
}

func TestArtifactCreateNeedsAName(t *testing.T) {
	srv, seen := uploadServer(t, 201, `{}`)
	file := filepath.Join(t.TempDir(), "page.html")
	write(t, file, "x")

	_, _, err := runPublicCmd(t, srv, "artifact", "create", file, "--project", "p1")
	if exitOf(err) != 2 || len(*seen) != 0 {
		t.Fatalf("err = %v, seen = %d", err, len(*seen))
	}
}

func TestTheSameFolderGivesTheSameBytesAndTheSameSeedKey(t *testing.T) {
	srv, seen := uploadServer(t, 201, `{}`)
	t.Setenv(seedEnv, "seed-1")
	dir := t.TempDir()
	write(t, filepath.Join(dir, "index.html"), "<p>a</p>")
	write(t, filepath.Join(dir, "b.css"), "b{}")

	for range 2 {
		if _, _, err := runPublicCmd(t, srv, "artifact", "create", dir, "--project", "p1", "--name", "N"); err != nil {
			t.Fatal(err)
		}
	}
	a, b := (*seen)[0], (*seen)[1]
	if a.key == "" || a.key != b.key || !bytes.Equal(a.body, b.body) || a.contentType != b.contentType {
		t.Fatalf("a retry must repeat the key and the bytes:\n%q\n%q", a.key, b.key)
	}

	write(t, filepath.Join(dir, "b.css"), "c{}")
	if _, _, err := runPublicCmd(t, srv, "artifact", "create", dir, "--project", "p1", "--name", "N"); err != nil {
		t.Fatal(err)
	}
	if (*seen)[2].key == a.key {
		t.Fatal("a changed file must change the key")
	}
}

func TestArtifactPushIsHiddenAndExitsTwoWithTheNewCommands(t *testing.T) {
	root := newRootCmd()
	var out bytes.Buffer
	root.SetOut(&out)
	root.SetErr(&out)
	root.SetArgs([]string{"artifact", "push", "x", "--name", "y"})
	err := root.Execute()
	if exitOf(err) != 2 || !strings.Contains(err.Error(), "artifact create") || !strings.Contains(err.Error(), "artifact version create") {
		t.Fatalf("err = %v", err)
	}
	for _, c := range root.Commands() {
		if c.Name() != "artifact" {
			continue
		}
		for _, sub := range c.Commands() {
			if sub.Name() == "push" && !sub.Hidden {
				t.Fatal("push must be hidden")
			}
		}
	}
}

func TestArtifactUpdateArchivesWithTheFlag(t *testing.T) {
	srv, seen := uploadServer(t, 200, `{"artifactId":"a1","url":"https://app/a1"}`)

	if _, _, err := runPublicCmd(t, srv, "artifact", "update", "a1", "--project", "p1", "--archived=false"); err != nil {
		t.Fatal(err)
	}
	if got := (*seen)[0]; got.method != "PATCH" || string(got.body) != `{"archived":false}` {
		t.Fatalf("request = %+v", got)
	}
}

func fileServer(t *testing.T, contentType string, data []byte) (*httptest.Server, *[]seenUpload) {
	t.Helper()
	var seen []seenUpload
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seen = append(seen, seenUpload{method: r.Method, path: r.URL.EscapedPath() + "?" + r.URL.RawQuery})
		w.Header().Set("Content-Type", contentType)
		_, _ = w.Write(data)
	}))
	t.Cleanup(srv.Close)

	return srv, &seen
}

func TestArtifactReadSendsThePathAsOneEncodedSegmentAndPrintsTheBytes(t *testing.T) {
	srv, seen := fileServer(t, "text/css", []byte("a{}\n\x00raw"))

	out, _, err := runPublicCmd(t, srv, "artifact", "read", "a1", "css/app.css", "--project", "p1", "--version-number", "3")
	if err != nil {
		t.Fatal(err)
	}
	if got := (*seen)[0]; got.path != "/api/v1/projects/p1/artifacts/a1/files/css%2Fapp.css?versionNumber=3" {
		t.Fatalf("path = %q", got.path)
	}
	if out != "a{}\n\x00raw" {
		t.Fatalf("a pipe must get the exact bytes, got %q", out)
	}
}

func TestArtifactReadHasNoFormatAndNoFieldsFlag(t *testing.T) {
	srv, seen := fileServer(t, "text/plain", []byte("x"))
	for _, flag := range []string{"--format", "--fields"} {
		_, _, err := runPublicCmd(t, srv, "artifact", "read", "a1", "f.txt", "--project", "p1", flag, "json")
		if exitOf(err) != 2 {
			t.Fatalf("%s: err = %v", flag, err)
		}
	}
	if len(*seen) != 0 {
		t.Fatal("a flag error must send nothing")
	}
}

func TestIsPlainTextRefusesInvalidUTF8AndControlCharacters(t *testing.T) {
	for in, want := range map[string]bool{
		"line\n\ttab\r\n": true,
		"é ü 日本":          true,
		"\x1b[31mred":     false,
		"a\x00b":          false,
		"\xff\xfe":        false,
		"del\x7f":         false,
		"c1\u0085":        false,
	} {
		if got := isPlainText([]byte(in)); got != want {
			t.Errorf("isPlainText(%q) = %v", in, got)
		}
	}
}

func TestOutputWritesTheFileAndRefusesAnExistingOneWithoutClobber(t *testing.T) {
	srv, _ := fileServer(t, "image/png", []byte("\x89PNG\x00"))
	dir := t.TempDir()
	target := filepath.Join(dir, "pic.png")

	stdout, stderr, err := runPublicCmd(t, srv, "artifact", "read", "a1", "pic.png", "--project", "p1", "--output", target)
	if err != nil {
		t.Fatal(err)
	}
	if stdout != "" {
		t.Fatalf("stdout must stay empty, got %q", stdout)
	}
	if !strings.Contains(stderr, target) {
		t.Fatalf("stderr must name the saved file, got %q", stderr)
	}
	if got, _ := os.ReadFile(target); string(got) != "\x89PNG\x00" {
		t.Fatalf("file = %q", got)
	}
	info, _ := os.Stat(target)
	if info.Mode().Perm()&0o600 != 0o600 {
		t.Fatalf("mode = %v", info.Mode())
	}

	if err := os.WriteFile(target, []byte("mine"), 0o644); err != nil {
		t.Fatal(err)
	}
	stdout, stderr, err = runPublicCmd(t, srv, "artifact", "read", "a1", "pic.png", "--project", "p1", "-o", target)
	if exitOf(err) != 2 {
		t.Fatalf("err = %v", err)
	}
	if stdout != "" || strings.Contains(stderr, "Saved") {
		t.Fatalf("a refused write must report no saved file: stdout = %q, stderr = %q", stdout, stderr)
	}
	if got, _ := os.ReadFile(target); string(got) != "mine" {
		t.Fatalf("the target must stay as it was, got %q", got)
	}
	if left, _ := filepath.Glob(filepath.Join(dir, ".loupe-*")); len(left) != 0 {
		t.Fatalf("temporary files left: %v", left)
	}

	stdout, stderr, err = runPublicCmd(t, srv, "artifact", "read", "a1", "pic.png", "--project", "p1", "-o", target, "--clobber")
	if err != nil {
		t.Fatal(err)
	}
	if stdout != "" || !strings.Contains(stderr, target) {
		t.Fatalf("clobber must also name the file on stderr only: stdout = %q, stderr = %q", stdout, stderr)
	}
	if got, _ := os.ReadFile(target); string(got) != "\x89PNG\x00" {
		t.Fatalf("clobber must replace the file, got %q", got)
	}
	if left, _ := filepath.Glob(filepath.Join(dir, ".loupe-*")); len(left) != 0 {
		t.Fatalf("temporary files left: %v", left)
	}
}

func TestOutputFolderExitsTwoAndClobberNeedsAFile(t *testing.T) {
	srv, seen := fileServer(t, "text/plain", []byte("x"))

	_, _, err := runPublicCmd(t, srv, "artifact", "read", "a1", "f.txt", "--project", "p1", "-o", t.TempDir())
	if exitOf(err) != 2 {
		t.Fatalf("a folder: err = %v", err)
	}
	_, _, err = runPublicCmd(t, srv, "artifact", "read", "a1", "f.txt", "--project", "p1", "--clobber")
	if exitOf(err) != 2 || len(*seen) != 0 {
		t.Fatalf("clobber alone: err = %v", err)
	}
}

func TestATerminalRefusesAFileThatIsNotPlainText(t *testing.T) {
	srv, _ := fileServer(t, "text/plain", []byte("\x1b[2Jboo"))
	old := isTerminalWriter
	isTerminalWriter = func(io.Writer) bool { return true }
	t.Cleanup(func() { isTerminalWriter = old })

	out, _, err := runPublicCmd(t, srv, "artifact", "read", "a1", "f.txt", "--project", "p1")
	if exitOf(err) != 2 || out != "" || !strings.Contains(err.Error(), "--output") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	out, _, err = runPublicCmd(t, srv, "artifact", "read", "a1", "f.txt", "--project", "p1", "--output", "-")
	if err != nil || out != "\x1b[2Jboo" {
		t.Fatalf("--output - prints anyway: err = %v, out = %q", err, out)
	}
}
