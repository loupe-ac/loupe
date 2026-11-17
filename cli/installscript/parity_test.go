package installscript

import (
	"os"
	"strings"
	"testing"
)

// keyLines returns the trimmed, non-empty lines of text.
func keyLines(text string) []string {
	var out []string
	for _, l := range strings.Split(text, "\n") {
		if l = strings.TrimSpace(l); l != "" {
			out = append(out, l)
		}
	}

	return out
}

// blocks returns the key lines of each release_keys block in a file.
func blocks(t *testing.T, path string) [][]string {
	t.Helper()
	src, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var out [][]string
	var current []string
	inside := false
	for _, l := range strings.Split(string(src), "\n") {
		switch strings.TrimSpace(l) {
		case "# BEGIN release_keys":
			if inside {
				t.Fatalf("%s holds a BEGIN marker inside a block", path)
			}
			inside, current = true, nil
		case "# END release_keys":
			if !inside {
				t.Fatalf("%s holds an END marker before its BEGIN marker", path)
			}
			inside = false
			out = append(out, keyLines(strings.Join(current, "\n")))
		default:
			if inside {
				current = append(current, l)
			}
		}
	}
	if inside {
		t.Fatalf("%s holds a BEGIN marker with no END marker", path)
	}

	return out
}

func justfilePath() string {
	if p := os.Getenv("LOUPE_JUSTFILE"); p != "" {
		return p
	}

	return "../../justfile"
}

func TestReleaseKeysMatchTheScriptsAndTheRecipe(t *testing.T) {
	raw, err := os.ReadFile("../internal/update/release_keys")
	if err != nil {
		t.Fatal(err)
	}
	want := keyLines(string(raw))
	if len(want) == 0 {
		t.Fatal("release_keys holds no key")
	}
	// The justfile holds one block for each recipe that checks a signature.
	for path, count := range map[string]int{"../install.sh": 1, justfilePath(): 2} {
		found := blocks(t, path)
		if len(found) != count {
			t.Errorf("%s holds %d release_keys blocks, want %d", path, len(found), count)
		}
		for _, got := range found {
			if strings.Join(got, "\n") != strings.Join(want, "\n") {
				t.Errorf("a key block of %s differs from release_keys:\n got: %q\nwant: %q", path, got, want)
			}
		}
	}
}
