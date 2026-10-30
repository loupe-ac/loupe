package logfile

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func at(day string) time.Time {
	t, err := time.ParseInLocation(dayLayout, day, time.Local)
	if err != nil {
		panic(err)
	}

	return t.Add(12 * time.Hour)
}

func openAt(t *testing.T, path, d string) *Writer {
	t.Helper()
	w, err := Open(path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { w.Close() })
	w.now = func() time.Time { return at(d) }

	return w
}

func put(t *testing.T, w *Writer, line string) {
	t.Helper()
	if _, err := w.Write([]byte(line + "\n")); err != nil {
		t.Fatal(err)
	}
	if err := os.Chtimes(w.path, w.now(), w.now()); err != nil {
		t.Fatal(err)
	}
}

func read(t *testing.T, path string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	return string(b)
}

func TestDatedPath(t *testing.T) {
	d := at("2026-10-29")
	for in, want := range map[string]string{
		"/x/bridge.log": "/x/bridge.2026-10-29.log",
		"/x/bridge":     "/x/bridge.2026-10-29",
		"/x/a.b.log":    "/x/a.b.2026-10-29.log",
	} {
		if got := DatedPath(in, d); got != want {
			t.Errorf("DatedPath(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestRotatesOnTheFirstWriteOfANewDay(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bridge.log")
	w := openAt(t, path, "2026-10-29")
	put(t, w, "one")
	put(t, w, "two")

	w.now = func() time.Time { return at("2026-10-30") }
	put(t, w, "three")

	if got := read(t, DatedPath(path, at("2026-10-29"))); got != "one\ntwo\n" {
		t.Errorf("dated file = %q", got)
	}
	if got := read(t, path); got != "three\n" {
		t.Errorf("live file = %q", got)
	}
	info, err := os.Stat(DatedPath(path, at("2026-10-29")))
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Errorf("dated file mode = %v, %v", info.Mode(), err)
	}
}

func TestRotationNamesTheDayOfTheLastWrite(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bridge.log")
	w := openAt(t, path, "2026-10-20")
	put(t, w, "old")

	w.now = func() time.Time { return at("2026-10-30") }
	put(t, w, "new")

	if got := read(t, DatedPath(path, at("2026-10-20"))); got != "old\n" {
		t.Errorf("dated file = %q", got)
	}
}

func TestRotationJoinsAnExistingDatedFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bridge.log")
	dated := DatedPath(path, at("2026-10-29"))
	if err := os.WriteFile(dated, []byte("early\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	w := openAt(t, path, "2026-10-29")
	put(t, w, "late")

	w.now = func() time.Time { return at("2026-10-30") }
	put(t, w, "next")

	if got := read(t, dated); got != "early\nlate\n" {
		t.Errorf("dated file = %q", got)
	}
}

func TestPrunesDatedFilesOlderThanThirteenDays(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bridge.log")
	for _, d := range []string{"2026-10-16", "2026-10-17", "2026-10-29"} {
		if err := os.WriteFile(DatedPath(path, at(d)), []byte(d+"\n"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	w := openAt(t, path, "2026-10-29")
	put(t, w, "a")

	w.now = func() time.Time { return at("2026-10-30") }
	put(t, w, "b")

	if _, err := os.Stat(DatedPath(path, at("2026-10-16"))); !os.IsNotExist(err) {
		t.Errorf("2026-10-17 is 13 days before 2026-10-30 and stays, 2026-10-16 goes: %v", err)
	}
	if _, err := os.Stat(DatedPath(path, at("2026-10-17"))); err != nil {
		t.Errorf("the file 13 days back stays: %v", err)
	}
}

func TestFamilyListsDatedFilesThenTheLiveFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bridge.log")
	for _, d := range []string{"2026-10-29", "2026-10-27"} {
		if err := os.WriteFile(DatedPath(path, at(d)), []byte("x\n"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.WriteFile(filepath.Join(filepath.Dir(path), "bridge.notadate.log"), nil, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte("x\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	got := Family(path)
	want := []string{DatedPath(path, at("2026-10-27")), DatedPath(path, at("2026-10-29")), path}
	if len(got) != len(want) {
		t.Fatalf("Family = %v, want %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Errorf("Family[%d] = %q, want %q", i, got[i], want[i])
		}
	}
}

func TestTwoWritersShareOneFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bridge.log")
	a := openAt(t, path, "2026-10-29")
	b := openAt(t, path, "2026-10-29")
	put(t, a, "from a")
	a.now = func() time.Time { return at("2026-10-30") }
	b.now = a.now
	put(t, b, "from b")
	put(t, a, "from a again")

	if got := read(t, path); got != "from b\nfrom a again\n" {
		t.Errorf("live file = %q", got)
	}
	if got := read(t, DatedPath(path, at("2026-10-29"))); got != "from a\n" {
		t.Errorf("dated file = %q", got)
	}
}
