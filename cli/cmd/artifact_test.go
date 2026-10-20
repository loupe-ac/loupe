package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/artifactcompile"
)

func write(t *testing.T, path, body string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestAFileIsStoredUnderItsBaseName(t *testing.T) {
	file := filepath.Join(t.TempDir(), "deep", "page.html")
	write(t, file, "<h1>Hi</h1>")

	files, err := collectArtifactFiles(file)
	if err != nil || len(files) != 1 {
		t.Fatalf("files = %v, err = %v", files, err)
	}
	if files[0].Path != "page.html" || files[0].ContentType != "text/html; charset=utf-8" || string(files[0].Contents) != "<h1>Hi</h1>" {
		t.Fatalf("file = %+v", files[0])
	}
}

func TestAFolderIsStoredWithRelativeSortedPathsAndNoGitFolder(t *testing.T) {
	dir := t.TempDir()
	write(t, filepath.Join(dir, "index.html"), "a")
	write(t, filepath.Join(dir, "css", "app.css"), "b")
	write(t, filepath.Join(dir, "data.unknownext"), "c")
	write(t, filepath.Join(dir, ".git", "HEAD"), "ref")

	files, err := collectArtifactFiles(dir)
	if err != nil {
		t.Fatal(err)
	}
	var paths []string
	for _, f := range files {
		paths = append(paths, f.Path)
	}
	if got := strings.Join(paths, ","); got != "css/app.css,data.unknownext,index.html" {
		t.Fatalf("paths = %s", got)
	}
	if files[1].ContentType != "application/octet-stream" {
		t.Fatalf("content type = %q", files[1].ContentType)
	}
}

func TestAnEmptyFolderIsRefused(t *testing.T) {
	if _, err := collectArtifactFiles(t.TempDir()); err == nil || !strings.Contains(err.Error(), "no file to store") {
		t.Fatalf("err = %v", err)
	}
}

func TestAFileThatIsNotRegularIsRefused(t *testing.T) {
	dir := t.TempDir()
	write(t, filepath.Join(dir, "real.html"), "a")
	if err := os.Symlink(filepath.Join(dir, "real.html"), filepath.Join(dir, "link.html")); err != nil {
		t.Skip("no symlinks here")
	}
	if _, err := collectArtifactFiles(dir); err == nil || !strings.Contains(err.Error(), "not a regular file") {
		t.Fatalf("err = %v", err)
	}
}

func TestAPushOverTheRequestCapIsRefused(t *testing.T) {
	file := filepath.Join(t.TempDir(), "big.bin")
	write(t, file, strings.Repeat("x", maxArtifactBytes+1))
	if _, err := collectArtifactFiles(file); err == nil || !strings.Contains(err.Error(), "larger than 8 MiB") {
		t.Fatalf("err = %v", err)
	}
}

func TestAPushNeedsExactlyOneOfNameAndArtifact(t *testing.T) {
	for name, args := range map[string][]string{
		"neither": {"push", "x"},
		"both":    {"push", "x", "--name", "N", "--artifact", "A"},
	} {
		t.Run(name, func(t *testing.T) {
			root := newRootCmd()
			root.SetArgs(append([]string{"artifact"}, args...))
			if err := root.Execute(); err == nil || !strings.Contains(err.Error(), "--name") {
				t.Fatalf("err = %v", err)
			}
		})
	}
}

func TestTheLinkJoinsTheInstanceAndThePath(t *testing.T) {
	if got := artifactLink("https://app.loupe.ac/", "/projects/p/artifacts/a"); got != "https://app.loupe.ac/projects/p/artifacts/a" {
		t.Fatalf("link = %q", got)
	}
}

func TestAFileFarOverTheCapIsRefusedWithoutReadingItAll(t *testing.T) {
	file := filepath.Join(t.TempDir(), "huge.bin")
	f, err := os.Create(file)
	if err != nil {
		t.Fatal(err)
	}
	if err := f.Truncate(1 << 34); err != nil {
		t.Skip("no sparse files here")
	}
	f.Close()
	if _, err := collectArtifactFiles(file); err == nil || !strings.Contains(err.Error(), "larger than 8 MiB") {
		t.Fatalf("err = %v", err)
	}
}

func TestADirectLinkIsRefused(t *testing.T) {
	dir := t.TempDir()
	write(t, filepath.Join(dir, "site", "index.html"), "a")
	for _, target := range []string{"site/index.html", "site"} {
		link := filepath.Join(dir, "link-"+filepath.Base(target))
		if err := os.Symlink(filepath.Join(dir, target), link); err != nil {
			t.Skip("no symlinks here")
		}
		if _, err := collectArtifactFiles(link); err == nil || !strings.Contains(err.Error(), "not a regular file") {
			t.Fatalf("%s: err = %v", target, err)
		}
	}
}

const canvasPage = "<html><head></head><body>\n<x-dc>\n<p>{{ title }}</p>\n</x-dc>\n" +
	"<script type=\"text/x-dc\" data-dc-script>\nclass Component extends DCLogic { renderVals() { return { title: 'Hi' }; } }\n</script>\n</body></html>"

func runArtifact(t *testing.T, args ...string) (stdout string, err error) {
	t.Helper()
	root := newRootCmd()
	var buf strings.Builder
	root.SetOut(&buf)
	root.SetArgs(append([]string{"artifact", "push"}, args...))
	err = root.Execute()

	return buf.String(), err
}

func TestOutWritesTheCompiledPageAndStoresNothing(t *testing.T) {
	src, out := t.TempDir(), filepath.Join(t.TempDir(), "out")
	write(t, filepath.Join(src, "Main.dc.html"), canvasPage)
	write(t, filepath.Join(src, "support.js"), "x")
	write(t, filepath.Join(src, "img", "a.png"), "png")

	stdout, err := runArtifact(t, src, "--out", out)
	if err != nil {
		t.Fatal(err)
	}
	page, err := os.ReadFile(filepath.Join(out, "index.html"))
	if err != nil || !strings.Contains(string(page), "<p>Hi</p>") {
		t.Fatalf("page = %s, err = %v", page, err)
	}
	if _, err := os.Stat(filepath.Join(out, "img", "a.png")); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(out, "support.js")); err == nil {
		t.Fatal("support.js was written")
	}
	if !strings.Contains(stdout, "Nothing is stored") {
		t.Fatalf("stdout = %q", stdout)
	}
}

func TestOutWithAGapWritesMarkersAndFails(t *testing.T) {
	src, out := t.TempDir(), filepath.Join(t.TempDir(), "out")
	write(t, filepath.Join(src, "Main.dc.html"), "<html><body>\n<x-dc>\n<button onClick=\"{{ go }}\">x</button>\n</x-dc>\n</body></html>")

	_, err := runArtifact(t, src, "--out", out)
	if err == nil || !strings.Contains(err.Error(), "1 gap marked") {
		t.Fatalf("err = %v", err)
	}
	page, _ := os.ReadFile(filepath.Join(out, "index.html"))
	if !strings.Contains(string(page), "<!-- loupe:gap event attribute onclick") {
		t.Fatalf("page = %s", page)
	}
}

func TestOutRefusesAFolderThatIsNotEmpty(t *testing.T) {
	src, out := t.TempDir(), t.TempDir()
	write(t, filepath.Join(src, "Main.dc.html"), canvasPage)
	write(t, filepath.Join(out, "keep.txt"), "x")

	if _, err := runArtifact(t, src, "--out", out); err == nil || !strings.Contains(err.Error(), "not empty") {
		t.Fatalf("err = %v", err)
	}
}

func TestOutWritesNothingOutsideItsFolder(t *testing.T) {
	out := filepath.Join(t.TempDir(), "out")
	for _, p := range []string{"../evil.html", "/abs.html", "a/../../evil.html"} {
		cmd := newArtifactPushCmd()
		err := writeCompiled(cmd, out, artifactcompile.Result{Files: []api.ArtifactFile{{Path: p}}})
		if err == nil || !strings.Contains(err.Error(), "outside") {
			t.Fatalf("%s: err = %v", p, err)
		}
	}
	if _, err := os.Stat(out); err == nil {
		t.Fatal("the folder was created")
	}
}

func TestAGapWithoutOutStoresNothingAndNamesEachGap(t *testing.T) {
	gaps := []artifactcompile.Gap{
		{File: "Main.dc.html", Line: 12, Feature: "event attribute onclick"},
		{File: "Main.dc.html", Line: 30, Feature: "<sc-else>"},
	}
	lines := strings.Split(gapError(gaps).Error(), "\n")
	if len(lines) != 2 || lines[0] != "cannot compile Main.dc.html, line 12: event attribute onclick is not supported. Run again with --out <folder> to get the pages with each gap marked." {
		t.Fatalf("lines = %q", lines)
	}
}

func TestACanvasWithAGapNeedsNoRemoteToBeRefused(t *testing.T) {
	src := t.TempDir()
	write(t, filepath.Join(src, "Main.dc.html"), "<html><body>\n<x-dc>\n<sc-else></sc-else>\n</x-dc>\n</body></html>")
	t.Setenv("HOME", t.TempDir())

	_, err := runArtifact(t, src, "--name", "N")
	if err == nil || !strings.Contains(err.Error(), "cannot compile Main.dc.html, line 3: <sc-else> is not supported. Run again with --out <folder>") {
		t.Fatalf("err = %v", err)
	}
}

func TestAPlainFolderPassesOutUnchanged(t *testing.T) {
	src, out := t.TempDir(), filepath.Join(t.TempDir(), "out")
	write(t, filepath.Join(src, "index.html"), "<h1>{{ not a canvas }}</h1>")

	if _, err := runArtifact(t, src, "--out", out); err != nil {
		t.Fatal(err)
	}
	got, _ := os.ReadFile(filepath.Join(out, "index.html"))
	if string(got) != "<h1>{{ not a canvas }}</h1>" {
		t.Fatalf("got = %s", got)
	}
}

func TestAPlainFolderWithAGapMarkerIsRefused(t *testing.T) {
	src := t.TempDir()
	write(t, filepath.Join(src, "index.html"), "<!-- loupe:gap event attribute onclick: x -->")
	write(t, filepath.Join(src, "pic.png"), "\x00loupe:gap")
	t.Setenv("HOME", t.TempDir())

	_, err := runArtifact(t, src, "--name", "N")
	if err == nil || !strings.Contains(err.Error(), "index.html still holds a loupe:gap comment") {
		t.Fatalf("err = %v", err)
	}
}

func TestACanvasWithACompanionGapMarkerIsRefused(t *testing.T) {
	src := t.TempDir()
	write(t, filepath.Join(src, "Main.dc.html"), "<html><body><x-dc><p>Hi</p></x-dc></body></html>")
	write(t, filepath.Join(src, "notes.html"), "<!-- loupe:gap event attribute onclick: x -->")
	t.Setenv("HOME", t.TempDir())

	_, err := runArtifact(t, src, "--name", "N")
	if err == nil || !strings.Contains(err.Error(), "notes.html still holds a loupe:gap comment") {
		t.Fatalf("err = %v", err)
	}
}

func TestABinaryFileWithMarkerTextIsNotAGapMarker(t *testing.T) {
	files := []api.ArtifactFile{{Path: "pic.png", Contents: []byte("\x00loupe:gap")}}
	if got := fileWithGapMarker(files); got != "" {
		t.Fatalf("got = %q", got)
	}
}

func TestIndexNamesThePageThatBecomesIndexHTML(t *testing.T) {
	src, out := t.TempDir(), filepath.Join(t.TempDir(), "out")
	write(t, filepath.Join(src, "Main.dc.html"), "<html><body><x-dc><p>Main</p></x-dc></body></html>")
	write(t, filepath.Join(src, "Other.dc.html"), "<html><body><x-dc><p>Other</p></x-dc></body></html>")

	if _, err := runArtifact(t, src, "--out", out, "--index", "Other"); err != nil {
		t.Fatal(err)
	}
	for name, want := range map[string]string{"index.html": "Other", "Main.html": "Main"} {
		got, err := os.ReadFile(filepath.Join(out, name))
		if err != nil || !strings.Contains(string(got), "<p>"+want+"</p>") {
			t.Fatalf("%s = %q, err = %v", name, got, err)
		}
	}
}

func TestSeveralCanvasesWithoutIndexAreAGapAndAnUnknownIndexIsRefused(t *testing.T) {
	src := t.TempDir()
	write(t, filepath.Join(src, "Main.dc.html"), "<html><body><x-dc><p>Main</p></x-dc></body></html>")
	write(t, filepath.Join(src, "Other.dc.html"), "<html><body><x-dc><p>Other</p></x-dc></body></html>")

	_, err := runArtifact(t, src, "--name", "N")
	if err == nil || !strings.Contains(err.Error(), "more than one artboard with no --index is not supported") {
		t.Fatalf("err = %v", err)
	}
	_, err = runArtifact(t, src, "--out", filepath.Join(t.TempDir(), "b"), "--index", "Nope")
	if err == nil || !strings.Contains(err.Error(), "--index Nope names no artboard") || !strings.Contains(err.Error(), "Main.dc.html, Other.dc.html") {
		t.Fatalf("err = %v", err)
	}
}
