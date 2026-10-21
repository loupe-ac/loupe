package setupbuild

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

const script = "#!/bin/sh\necho checked\n"

// builtSetup writes bin/loupe and a build.json that matches it.
func builtSetup(t *testing.T) string {
	t.Helper()
	x := t.TempDir()
	if err := os.MkdirAll(filepath.Join(x, "bin"), 0o755); err != nil {
		t.Fatal(err)
	}
	writeBin(t, x, script)
	writeRecord(t, x, script)

	return x
}

func writeBin(t *testing.T, x, body string) {
	t.Helper()
	p := BinPath(x)
	_ = os.Remove(p)
	if err := os.WriteFile(p, []byte(body), 0o755); err != nil {
		t.Fatal(err)
	}
}

func writeRecord(t *testing.T, x, body string) {
	t.Helper()
	sum := sha256.Sum256([]byte(body))
	b, _ := json.Marshal(Record{Checkout: "/co", Commit: "abc", Size: int64(len(body)), SHA256: hex.EncodeToString(sum[:])})
	if err := os.WriteFile(RecordPath(x), b, 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestBuiltNeedsABinaryOrARecord(t *testing.T) {
	x := t.TempDir()
	if Built(x) {
		t.Fatal("an empty setup is not built")
	}
	_ = os.WriteFile(RecordPath(x), []byte("{}"), 0o644)
	if !Built(x) {
		t.Fatal("a record alone makes a built setup")
	}
}

func TestCheckRefusesEveryBadBuild(t *testing.T) {
	cases := map[string]func(t *testing.T, x string){
		"no record":      func(t *testing.T, x string) { _ = os.Remove(RecordPath(x)) },
		"no binary":      func(t *testing.T, x string) { _ = os.Remove(BinPath(x)) },
		"wrong hash":     func(t *testing.T, x string) { writeBin(t, x, "#!/bin/sh\necho other!\n") },
		"wrong size":     func(t *testing.T, x string) { writeBin(t, x, script+"x") },
		"group writable": func(t *testing.T, x string) { _ = os.Chmod(BinPath(x), 0o775) },
		"other writable": func(t *testing.T, x string) { _ = os.Chmod(BinPath(x), 0o757) },
	}
	for name, spoil := range cases {
		t.Run(name, func(t *testing.T) {
			x := builtSetup(t)
			if _, err := Check(x); err != nil {
				t.Fatalf("a good build was refused: %v", err)
			}
			spoil(t, x)

			_, err := Check(x)
			var refused *RefusedError
			if !errors.As(err, &refused) {
				t.Fatalf("err = %v, want a refusal", err)
			}
			want := BinPath(x) + " is not the build that just cli-setup checked; run just cli-setup " + x
			if !strings.HasPrefix(err.Error(), want) {
				t.Fatalf("message = %q", err.Error())
			}
		})
	}
}

func TestPinKeepsTheCheckedBytesAfterTheBinaryIsReplaced(t *testing.T) {
	x := builtSetup(t)
	pin, err := Pin(x)
	if err != nil {
		t.Fatal(err)
	}
	file := filepath.Join(pin, "loupe")
	if info, err := os.Stat(file); err != nil || info.Mode().Perm() != 0o500 {
		t.Fatalf("pin file: %v, %v", info, err)
	}
	if info, err := os.Stat(pin); err != nil || info.Mode().Perm() != 0o700 {
		t.Fatalf("pin folder: %v, %v", info, err)
	}
	if !InPin(x, file) {
		t.Fatal("InPin does not know the pin")
	}

	writeBin(t, x, "#!/bin/sh\necho swapped\n")
	if out, err := exec.Command(file).Output(); err != nil || string(out) != "checked\n" {
		t.Fatalf("the pin ran %q, %v", out, err)
	}
	if again, err := Pin(x); err != nil || again != pin {
		t.Fatalf("the pin of the checked build is reused: %q, %v", again, err)
	}
}

func TestASwappedBinaryNeverBecomesAPin(t *testing.T) {
	x := builtSetup(t)
	writeBin(t, x, "#!/bin/sh\necho swapped\n")
	if _, err := Pin(x); err == nil {
		t.Fatal("a swapped binary must not become a pin")
	}
}

func TestPinIsReusedAndOtherPinsArePruned(t *testing.T) {
	x := builtSetup(t)
	first, err := Pin(x)
	if err != nil {
		t.Fatal(err)
	}
	second, err := Pin(x)
	if err != nil || second != first {
		t.Fatalf("pin = %q, %v; want the first one, %q", second, err, first)
	}

	other := filepath.Join(pinRoot(x), "deadbeef")
	if err := os.MkdirAll(other, 0o700); err != nil {
		t.Fatal(err)
	}
	PrunePins(x, first)
	if _, err := os.Stat(other); err == nil {
		t.Fatal("the pin of another build stayed")
	}
	if _, err := os.Stat(filepath.Join(first, "loupe")); err != nil {
		t.Fatalf("the current pin went: %v", err)
	}
}

func TestPinOfASetupThatIsNotBuiltIsEmpty(t *testing.T) {
	if pin, err := Pin(t.TempDir()); pin != "" || err != nil {
		t.Fatalf("pin = %q, %v", pin, err)
	}
}
