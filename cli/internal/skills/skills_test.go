package skills

import (
	"os"
	"path/filepath"
	"testing"
	"testing/fstest"
)

func fakeBundle() fstest.MapFS {
	return fstest.MapFS{
		"bundle/.placeholder":                               {Data: []byte("x")},
		"bundle/loupe/.claude-plugin/plugin.json":           {Data: []byte(`{"version":"1.2.0"}`)},
		"bundle/loupe/skills/loupe-board/SKILL.md":          {Data: []byte("board")},
		"bundle/loupe/hooks/run.sh":                         {Data: []byte("#!/bin/sh"), Mode: 0o755},
		"bundle/loupe-lifecycle/.claude-plugin/plugin.json": {Data: []byte(`{"version":"0.3.0"}`)},
	}
}

func TestInstallWritesEachPackageOnce(t *testing.T) {
	dir := t.TempDir()

	written, err := install(fakeBundle(), "bundle", dir)
	if err != nil {
		t.Fatal(err)
	}
	if len(written) != 2 {
		t.Fatalf("written = %v, want two packages", written)
	}
	if _, err := os.Stat(filepath.Join(dir, "skills", "loupe", "1.2.0", "skills", "loupe-board", "SKILL.md")); err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(filepath.Join(dir, "skills", "loupe", "1.2.0", "hooks", "run.sh"))
	if err != nil || info.Mode()&0o100 == 0 {
		t.Fatalf("hook is not executable: %v %v", info, err)
	}

	again, err := install(fakeBundle(), "bundle", dir)
	if err != nil {
		t.Fatal(err)
	}
	if len(again) != 0 {
		t.Fatalf("second install wrote %v, want nothing", again)
	}
}

func TestInstallRefusesAPackageWithNoVersion(t *testing.T) {
	fsys := fstest.MapFS{"bundle/x/.claude-plugin/plugin.json": {Data: []byte(`{}`)}}

	if _, err := install(fsys, "bundle", t.TempDir()); err == nil {
		t.Fatal("want an error")
	}
}

func TestEmbeddedBundleInstallsOnce(t *testing.T) {
	dir := t.TempDir()

	if _, err := Install(dir); err != nil {
		t.Fatal(err)
	}
	again, err := Install(dir)
	if err != nil {
		t.Fatal(err)
	}
	if len(again) != 0 {
		t.Fatalf("second install wrote %v, want nothing", again)
	}
}
