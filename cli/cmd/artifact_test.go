package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
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
	if got := artifactLink("https://loupe.ac/", "/projects/p/artifacts/a"); got != "https://loupe.ac/projects/p/artifacts/a" {
		t.Fatalf("link = %q", got)
	}
}
