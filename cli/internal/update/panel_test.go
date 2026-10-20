package update

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type tarEntry struct {
	name   string
	typ    byte
	mode   int64
	body   string
	target string
}

func file(name, body string, mode int64) tarEntry {
	return tarEntry{name: name, typ: tar.TypeReg, mode: mode, body: body}
}

func dirEntry(name string) tarEntry { return tarEntry{name: name, typ: tar.TypeDir, mode: 0o755} }

func link(name, target string) tarEntry {
	return tarEntry{name: name, typ: tar.TypeSymlink, mode: 0o755, target: target}
}

func buildTar(t *testing.T, entries ...tarEntry) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for _, e := range entries {
		hdr := &tar.Header{Name: e.name, Typeflag: e.typ, Mode: e.mode, Linkname: e.target}
		if e.typ == tar.TypeReg {
			hdr.Size = int64(len(e.body))
		}
		if err := tw.WriteHeader(hdr); err != nil {
			t.Fatal(err)
		}
		if e.typ == tar.TypeReg {
			if _, err := tw.Write([]byte(e.body)); err != nil {
				t.Fatal(err)
			}
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}

	return buf.Bytes()
}

func panelEntries() []tarEntry {
	return []tarEntry{
		file("loupe", "binary", 0o755),
		dirEntry("Live review.app/"),
		dirEntry("Live review.app/Contents/"),
		file("Live review.app/Contents/Info.plist", "plist", 0o644),
		file("Live review.app/Contents/MacOS/panel", "exe", 0o755),
		link("Live review.app/Contents/Current", "MacOS"),
	}
}

func TestPanelDirIsTheVersionFolderUnderLiveReview(t *testing.T) {
	if got, want := PanelDir("/cfg", "1.2.3"), filepath.Join("/cfg", "live-review", "1.2.3"); got != want {
		t.Fatalf("PanelDir = %q, want %q", got, want)
	}
}

func TestExtractPanelUnpacksTheAppWithModesAndSymlinks(t *testing.T) {
	dest := filepath.Join(t.TempDir(), "live-review", "1.2.3")

	found, err := ExtractPanel(buildTar(t, panelEntries()...), dest)
	if err != nil || !found {
		t.Fatalf("found = %v, err = %v", found, err)
	}
	app := filepath.Join(dest, PanelAppName)
	if data, _ := os.ReadFile(filepath.Join(app, "Contents/Info.plist")); string(data) != "plist" {
		t.Fatalf("plist = %q", data)
	}
	info, err := os.Stat(filepath.Join(app, "Contents/MacOS/panel"))
	if err != nil || info.Mode().Perm() != 0o755 {
		t.Fatalf("panel mode = %v, err = %v", info, err)
	}
	if got, err := os.Readlink(filepath.Join(app, "Contents/Current")); err != nil || got != "MacOS" {
		t.Fatalf("link = %q, err = %v", got, err)
	}
	if _, err := os.Stat(filepath.Join(dest, "loupe")); err == nil {
		t.Fatal("the binary must not land in the panel folder")
	}
	entries, _ := os.ReadDir(dest)
	if len(entries) != 1 {
		t.Fatalf("dest holds %d entries, want only the app", len(entries))
	}
}

func TestExtractPanelReturnsFalseForAnArchiveWithNoPanel(t *testing.T) {
	dest := filepath.Join(t.TempDir(), "p")

	found, err := ExtractPanel(buildTar(t, file("loupe", "binary", 0o755)), dest)
	if found || err != nil {
		t.Fatalf("found = %v, err = %v", found, err)
	}
	if _, err := os.Stat(dest); err == nil {
		t.Fatal("no folder must appear")
	}
}

func TestExtractPanelReplacesAnExistingApp(t *testing.T) {
	dest := t.TempDir()
	old := filepath.Join(dest, PanelAppName, "old.txt")
	if err := os.MkdirAll(filepath.Dir(old), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(old, []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}

	if found, err := ExtractPanel(buildTar(t, panelEntries()...), dest); err != nil || !found {
		t.Fatalf("found = %v, err = %v", found, err)
	}
	if _, err := os.Stat(old); err == nil {
		t.Fatal("the old app must be gone")
	}
	if _, err := os.Stat(filepath.Join(dest, PanelAppName, "Contents/Info.plist")); err != nil {
		t.Fatal(err)
	}
	entries, _ := os.ReadDir(dest)
	if len(entries) != 1 {
		t.Fatalf("dest holds %d entries, want only the app", len(entries))
	}
}

func TestExtractPanelRejectsUnsafeArchives(t *testing.T) {
	cases := map[string][]tarEntry{
		"absolute path":      {file("/etc/passwd", "x", 0o644)},
		"dot dot":            {file("Live review.app/../../evil", "x", 0o644)},
		"dot dot elsewhere":  {file("../evil", "x", 0o644)},
		"hard link":          {dirEntry("Live review.app/"), {name: "Live review.app/h", typ: tar.TypeLink, target: "loupe"}},
		"device":             {dirEntry("Live review.app/"), {name: "Live review.app/d", typ: tar.TypeChar, mode: 0o644}},
		"fifo":               {dirEntry("Live review.app/"), {name: "Live review.app/f", typ: tar.TypeFifo, mode: 0o644}},
		"absolute symlink":   {dirEntry("Live review.app/"), link("Live review.app/l", "/etc")},
		"escaping symlink":   {dirEntry("Live review.app/"), link("Live review.app/l", "../../etc")},
		"nested escape":      {dirEntry("Live review.app/"), link("Live review.app/a/l", "../../x")},
		"symlink chain":      {dirEntry("Live review.app/"), link("Live review.app/b", "."), link("Live review.app/l", "b/..")},
		"write through link": {dirEntry("Live review.app/"), link("Live review.app/b", "."), file("Live review.app/b/f", "x", 0o644)},
		"app is a file":      {file("Live review.app", "x", 0o644)},
		"duplicate file":     {file("Live review.app/f", "a", 0o644), file("Live review.app/f", "b", 0o644)},
	}
	for name, entries := range cases {
		t.Run(name, func(t *testing.T) {
			parent := t.TempDir()
			dest := filepath.Join(parent, "p")

			found, err := ExtractPanel(buildTar(t, entries...), dest)
			if err == nil || found {
				t.Fatalf("found = %v, err = %v; want an error", found, err)
			}
			if _, err := os.Stat(filepath.Join(dest, PanelAppName)); err == nil {
				t.Fatal("a rejected archive must leave no app")
			}
			if left, _ := os.ReadDir(dest); len(left) != 0 {
				t.Fatalf("a rejected archive left %d entries", len(left))
			}
		})
	}
}

func TestExtractPanelCapsTheUnpackedSize(t *testing.T) {
	old := maxPanelBytes
	maxPanelBytes = 10
	t.Cleanup(func() { maxPanelBytes = old })

	_, err := ExtractPanel(buildTar(t, file("Live review.app/a", "123456", 0o644), file("Live review.app/b", "123456", 0o644)), t.TempDir())
	if !errors.Is(err, ErrPanelTooBig) {
		t.Fatalf("err = %v", err)
	}
}

func TestExtractPanelCapsTheEntryCount(t *testing.T) {
	old := maxPanelEntries
	maxPanelEntries = 2
	t.Cleanup(func() { maxPanelEntries = old })

	_, err := ExtractPanel(buildTar(t, file("Live review.app/a", "", 0o644), file("Live review.app/b", "", 0o644), file("Live review.app/c", "", 0o644)), t.TempDir())
	if !errors.Is(err, ErrPanelTooBig) {
		t.Fatalf("err = %v", err)
	}
}

func TestExtractPanelRejectsAnArchiveThatIsNotGzip(t *testing.T) {
	if _, err := ExtractPanel([]byte("nope"), t.TempDir()); err == nil || !strings.Contains(err.Error(), "archive") {
		t.Fatalf("err = %v", err)
	}
}
