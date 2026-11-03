package update

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func TestExtractArchiveWritesTheEntriesUnderRoot(t *testing.T) {
	root := t.TempDir()
	archive := buildTar(t,
		dirEntry("./"),
		dirEntry("./skills/"),
		file("./skills/a/SKILL.md", "hello", 0o644),
		file("./bin/run.sh", "#!/bin/sh", 0o755),
		link("./skills/b", "a"),
	)

	if err := ExtractArchive(archive, root); err != nil {
		t.Fatal(err)
	}
	if data, err := os.ReadFile(filepath.Join(root, "skills", "a", "SKILL.md")); err != nil || string(data) != "hello" {
		t.Fatalf("file = %q, %v", data, err)
	}
	if info, err := os.Stat(filepath.Join(root, "bin", "run.sh")); err != nil || info.Mode().Perm() != 0o755 {
		t.Fatalf("mode = %v, %v", info, err)
	}
	if target, err := os.Readlink(filepath.Join(root, "skills", "b")); err != nil || target != "a" {
		t.Fatalf("link = %q, %v", target, err)
	}
}

func TestExtractArchiveRefusesAnUnsafeEntry(t *testing.T) {
	cases := map[string][]tarEntry{
		"absolute path":     {file("/etc/passwd", "x", 0o644)},
		"dot dot":           {file("a/../../x", "x", 0o644)},
		"backslash":         {file(`a\b`, "x", 0o644)},
		"absolute symlink":  {link("l", "/etc")},
		"symlink leaves":    {link("l", "../outside")},
		"chain leaves":      {dirEntry("d"), link("d/up", ".."), link("up2", "d/up/..")},
		"write through":     {link("l", "."), file("l/x", "x", 0o644)},
		"duplicate file":    {file("a", "1", 0o644), file("a", "2", 0o644)},
		"hard link":         {tarEntry{name: "h", typ: '1', target: "a"}},
		"symlink then file": {link("s", "a"), dirEntry("s/")},
	}
	for name, entries := range cases {
		t.Run(name, func(t *testing.T) {
			root := t.TempDir()
			err := ExtractArchive(buildTar(t, entries...), root)
			if !errors.Is(err, ErrArchiveUnsafe) {
				t.Fatalf("err = %v; want ErrArchiveUnsafe", err)
			}
		})
	}
}

func TestExtractArchiveRefusesAnArchiveAboveTheCaps(t *testing.T) {
	oldBytes, oldEntries := maxArchiveBytes, maxArchiveEntries
	t.Cleanup(func() { maxArchiveBytes, maxArchiveEntries = oldBytes, oldEntries })

	maxArchiveBytes = 10
	err := ExtractArchive(buildTar(t, file("a", "0123456789ab", 0o644)), t.TempDir())
	if !errors.Is(err, ErrArchiveTooBig) || errors.Is(err, ErrArchiveUnsafe) {
		t.Fatalf("size: err = %v; want ErrArchiveTooBig", err)
	}

	maxArchiveBytes, maxArchiveEntries = oldBytes, 2
	err = ExtractArchive(buildTar(t, file("a", "", 0o644), file("b", "", 0o644), file("c", "", 0o644)), t.TempDir())
	if !errors.Is(err, ErrArchiveTooBig) {
		t.Fatalf("entries: err = %v; want ErrArchiveTooBig", err)
	}
}

func TestExtractArchiveRefusesBytesThatAreNoArchive(t *testing.T) {
	if err := ExtractArchive([]byte("nope"), t.TempDir()); err == nil || errors.Is(err, ErrArchiveUnsafe) {
		t.Fatalf("err = %v", err)
	}
}
