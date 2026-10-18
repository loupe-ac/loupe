package rules

import (
	"bytes"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// onPath fakes a PATH that holds exactly the named programs.
func onPath(names ...string) func(string) (string, error) {
	return func(name string) (string, error) {
		if slices.Contains(names, name) {
			return "/bin/" + name, nil
		}

		return "", errors.New("not found")
	}
}

func firstRunOptions(t *testing.T, goos string, names ...string) FirstRunOptions {
	t.Helper()

	return FirstRunOptions{Slug: "my-app", Dir: t.TempDir(), LookPath: onPath(names...), GOOS: goos}
}

func TestFirstRunPicksTheHarnessOfWhatIsInstalled(t *testing.T) {
	for name, tc := range map[string]struct {
		on   []string
		want string
	}{
		"claude":        {[]string{"claude"}, HarnessClaudeCode},
		"both":          {[]string{"claude", "codex"}, HarnessClaudeCode},
		"codex only":    {[]string{"codex"}, HarnessCodex},
		"neither":       {nil, HarnessClaudeCode},
		"unrelated one": {[]string{"git"}, HarnessClaudeCode},
	} {
		t.Run(name, func(t *testing.T) {
			if got := firstRunOptions(t, "darwin", tc.on...).Harness(); got != tc.want {
				t.Fatalf("Harness() = %q, want %q", got, tc.want)
			}
		})
	}
}

func TestTerminalFollowsTheMachine(t *testing.T) {
	for name, tc := range map[string]struct {
		goos string
		on   []string
		want []string
	}{
		"macOS":                {"darwin", nil, []string{"open", "-a", "Terminal", "{script}"}},
		"gnome":                {"linux", []string{"gnome-terminal", "xterm"}, []string{"gnome-terminal", "--", "{script}"}},
		"konsole":              {"linux", []string{"konsole"}, []string{"konsole", "-e", "{script}"}},
		"xfce":                 {"linux", []string{"xfce4-terminal"}, []string{"xfce4-terminal", "-x", "{script}"}},
		"kitty":                {"linux", []string{"kitty"}, []string{"kitty", "{script}"}},
		"alacritty":            {"linux", []string{"alacritty"}, []string{"alacritty", "-e", "{script}"}},
		"xterm last":           {"linux", []string{"xterm"}, []string{"xterm", "-e", "{script}"}},
		"first of several":     {"linux", []string{"xterm", "kitty", "konsole"}, []string{"konsole", "-e", "{script}"}},
		"linux without any":    {"linux", []string{"git"}, nil},
		"windows":              {"windows", []string{"xterm"}, nil},
		"an OS we do not know": {"plan9", nil, nil},
	} {
		t.Run(name, func(t *testing.T) {
			got := firstRunOptions(t, tc.goos, tc.on...).Terminal()
			if !slices.Equal(got, tc.want) {
				t.Fatalf("Terminal() = %v, want %v", got, tc.want)
			}
		})
	}
}

func TestFirstRunWritesAFileTheBridgeLoads(t *testing.T) {
	for name, tc := range map[string]struct {
		goos        string
		on          []string
		account     string
		interactive bool
	}{
		"macOS with claude":  {"darwin", []string{"claude"}, "claude-code", true},
		"linux with codex":   {"linux", []string{"codex", "kitty"}, "codex", true},
		"linux no terminal":  {"linux", []string{"claude"}, "claude-code", false},
		"windows":            {"windows", []string{"claude"}, "claude-code", false},
		"macOS with nothing": {"darwin", nil, "claude-code", true},
	} {
		t.Run(name, func(t *testing.T) {
			o := firstRunOptions(t, tc.goos, tc.on...)
			data, err := FirstRun(o)
			if err != nil {
				t.Fatal(err)
			}
			text := string(data)

			set, err := Parse(data, Defaults{})
			if err != nil {
				t.Fatalf("the file does not load: %v\n%s", err, data)
			}
			if set.HasInteractive() != tc.interactive {
				t.Fatalf("HasInteractive() = %v, want %v\n%s", set.HasInteractive(), tc.interactive, text)
			}
			if !strings.Contains(text, "appPrompts: true") || !strings.Contains(text, "account: "+tc.account) || !strings.Contains(text, "permissions: workspace") {
				t.Fatalf("missing a first-run key:\n%s", text)
			}
			if strings.Contains(text, "isolation") {
				t.Fatalf("first-run writes no isolation line:\n%s", text)
			}
			if got := strings.Contains(text, "launch:"); got != tc.interactive {
				t.Fatalf("launch present = %v, want %v\n%s", got, tc.interactive, text)
			}
			if got := strings.Contains(text, "work:"); got != tc.interactive {
				t.Fatalf("work present = %v, want %v\n%s", got, tc.interactive, text)
			}
		})
	}
}

func TestFirstRunFileForMacOS(t *testing.T) {
	o := FirstRunOptions{Slug: "my-app", Dir: "~/Code/my-app", LookPath: onPath("claude"), GOOS: "darwin"}
	data, err := FirstRun(o)
	if err != nil {
		t.Fatal(err)
	}

	want := `appPrompts: true
accounts:
  claude-code:
    harness: claude-code
defaults:
  account: claude-code
  permissions: workspace
projects:
  my-app:
    dir: ~/Code/my-app
launch:
  command: ["open", "-a", "Terminal", "{script}"]
work:
  product-design:
    action: interactive
    prompt: /loupe:product-design {cardNumber}
`
	if string(data) != want {
		t.Fatalf("got:\n%s\nwant:\n%s", data, want)
	}
}

func TestFirstRunQuotesADirectoryThatNeedsIt(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "my app: v2 #1")
	if err := os.Mkdir(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	data, err := FirstRun(FirstRunOptions{Slug: "my-app", Dir: dir, LookPath: onPath(), GOOS: "darwin"})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := Parse(data, Defaults{}); err != nil {
		t.Fatalf("the file does not load: %v\n%s", err, data)
	}
	if !strings.Contains(string(data), "v2 #1") {
		t.Fatalf("the directory was lost:\n%s", data)
	}
}

func TestTheExampleIsTheFirstRunFile(t *testing.T) {
	data, err := FirstRun(FirstRunOptions{Slug: "my-app", Dir: "~/Code/my-app", LookPath: onPath("claude"), GOOS: "darwin"})
	if err != nil {
		t.Fatal(err)
	}
	if Example != string(data) {
		t.Fatalf("Example drifted from FirstRun:\n%s", data)
	}
}

const partialRules = `# my rules
accounts:
  work: # the main one
    harness: claude-code

defaults:
  account: work # keep this

projects:
  other:
    dir: {other} # not this repository

work:
  # the old entry
  implement:
    prompt: go
`

func writePartial(t *testing.T, body string) string {
	t.Helper()

	return strings.ReplaceAll(body, "{other}", t.TempDir())
}

func TestMergeAddsOnlyWhatIsMissingAndKeepsComments(t *testing.T) {
	o := firstRunOptions(t, "darwin", "claude")
	existing := writePartial(t, partialRules)

	out, added, err := Merge([]byte(existing), o)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"appPrompts", "defaults.permissions", "projects.my-app", "launch", "work.product-design"}
	if !slices.Equal(added, want) {
		t.Fatalf("added = %v, want %v", added, want)
	}
	text := string(out)
	for _, kept := range []string{"# my rules", "# the main one", "# keep this", "# not this repository", "# the old entry", "account: work", "prompt: go"} {
		if !strings.Contains(text, kept) {
			t.Fatalf("lost %q:\n%s", kept, text)
		}
	}
	if strings.Contains(text, "claude-code:") {
		t.Fatalf("merge must not add an account:\n%s", text)
	}
	set, err := Parse(out, Defaults{})
	if err != nil {
		t.Fatalf("the merged file does not load: %v\n%s", err, text)
	}
	if !set.HasInteractive() {
		t.Fatalf("merge must add the interactive entry:\n%s", text)
	}

	again, addedAgain, err := Merge(out, o)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(again, out) || len(addedAgain) != 0 {
		t.Fatalf("a second merge changed the file (%v):\n%s", addedAgain, again)
	}
}

func TestMergeLeavesAFullFileByteIdentical(t *testing.T) {
	o := firstRunOptions(t, "darwin", "claude")
	data, err := FirstRun(o)
	if err != nil {
		t.Fatal(err)
	}
	// Odd layout and a comment would not survive a re-encode.
	odd := append([]byte("#   header\n"), bytes.ReplaceAll(data, []byte("  "), []byte("    "))...)

	for name, in := range map[string][]byte{"generated": data, "reindented": odd} {
		out, added, err := Merge(in, o)
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		if !bytes.Equal(out, in) || len(added) != 0 {
			t.Fatalf("%s: merge changed a complete file (%v):\n%s", name, added, out)
		}
	}
}

func TestMergeNeverChangesAPresentKey(t *testing.T) {
	o := firstRunOptions(t, "darwin", "claude")
	other := t.TempDir()
	existing := `appPrompts: false
accounts:
  work:
    harness: claude-code
defaults:
  account: work
  permissions: full
projects:
  my-app:
    dir: ` + other + `
launch:
  command: ["my-terminal", "{script}"]
work:
  product-design:
    action: interactive
    prompt: my own prompt
`
	out, added, err := Merge([]byte(existing), o)
	if err != nil {
		t.Fatal(err)
	}
	if string(out) != existing || len(added) != 0 {
		t.Fatalf("merge changed a present key (%v):\n%s", added, out)
	}
}

func TestMergeWritesNoLauncherWhenNoneIsKnown(t *testing.T) {
	o := firstRunOptions(t, "linux", "claude")
	out, added, err := Merge([]byte(writePartial(t, partialRules)), o)
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"appPrompts", "defaults.permissions", "projects.my-app"}; !slices.Equal(added, want) {
		t.Fatalf("added = %v, want %v", added, want)
	}
	if strings.Contains(string(out), "launch") || strings.Contains(string(out), "product-design") {
		t.Fatalf("no launcher is known, so merge adds none:\n%s", out)
	}
}

func TestMergeFillsEmptyBlocksAndAnEmptyFile(t *testing.T) {
	o := firstRunOptions(t, "darwin", "claude")

	out, _, err := Merge([]byte("accounts:\n  c:\n    harness: claude-code\ndefaults:\n  account: c\nprojects:\nwork:\n"), o)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := Parse(out, Defaults{}); err != nil {
		t.Fatalf("the merged file does not load: %v\n%s", err, out)
	}

	fresh, err := FirstRun(o)
	if err != nil {
		t.Fatal(err)
	}
	for _, blank := range []string{"", "\n  \n"} {
		got, _, err := Merge([]byte(blank), o)
		if err != nil || !bytes.Equal(got, fresh) {
			t.Fatalf("Merge(%q) = %q, %v, want a new file", blank, got, err)
		}
	}
}

func TestMergeRefusesAFileItCannotEdit(t *testing.T) {
	o := firstRunOptions(t, "darwin", "claude")
	for name, body := range map[string]string{
		"not YAML":        "a: [broken\n",
		"a list":          "- a\n- b\n",
		"comments only":   "# nothing\n",
		"projects a list": "projects:\n  - a\n",
		"two documents":   "a: 1\n---\nb: 2\n",
	} {
		if out, _, err := Merge([]byte(body), o); err == nil {
			t.Fatalf("%s: got no error, wrote:\n%s", name, out)
		}
	}
}

func TestResetRewritesTheFileAndKeepsItsProjects(t *testing.T) {
	o := firstRunOptions(t, "darwin", "claude")
	other := t.TempDir()
	existing := `# old
accounts:
  gone:
    harness: codex
defaults:
  account: gone
  isolation: none
projects:
  # keep me
  other:
    dir: ` + other + `
work:
  implement:
    prompt: go
`
	out, err := Reset([]byte(existing), o)
	if err != nil {
		t.Fatal(err)
	}
	text := string(out)
	for _, gone := range []string{"# old", "gone", "isolation", "implement"} {
		if strings.Contains(text, gone) {
			t.Fatalf("reset kept %q:\n%s", gone, text)
		}
	}
	for _, kept := range []string{"# keep me", "other:", other, "my-app:", o.Dir, "appPrompts: true", "product-design"} {
		if !strings.Contains(text, kept) {
			t.Fatalf("reset lost %q:\n%s", kept, text)
		}
	}
	if _, err := Parse(out, Defaults{}); err != nil {
		t.Fatalf("the reset file does not load: %v\n%s", err, text)
	}

	for _, body := range []string{"", "defaults:\n  account: x\n"} {
		fresh, err := FirstRun(o)
		if err != nil {
			t.Fatal(err)
		}
		if got, err := Reset([]byte(body), o); err != nil || !bytes.Equal(got, fresh) {
			t.Fatalf("Reset(%q) = %q, %v, want a new file", body, got, err)
		}
	}
	if _, err := Reset([]byte("a: [broken\n"), o); err == nil {
		t.Fatal("reset must not discard a file it cannot read")
	}
}

func TestResetKeepsTheAutoUpdateKey(t *testing.T) {
	o := firstRunOptions(t, "darwin", "claude")
	other := t.TempDir()
	for name, existing := range map[string]string{
		"with projects":    "autoUpdate: true\nprojects:\n  other:\n    dir: " + other + "\n",
		"without projects": "# mine\nautoUpdate: false\nmaxWorkers: 3\n",
	} {
		out, err := Reset([]byte(existing), o)
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		want := "autoUpdate: true"
		if name == "without projects" {
			want = "autoUpdate: false"
		}
		text := string(out)
		if strings.Count(text, "autoUpdate:") != 1 || !strings.Contains(text, want) {
			t.Errorf("%s: reset must keep %q once:\n%s", name, want, text)
		}
		if strings.Contains(text, "maxWorkers") {
			t.Errorf("%s: reset kept a key it should drop:\n%s", name, text)
		}
		if _, err := Parse(out, Defaults{}); err != nil {
			t.Errorf("%s: the reset file does not load: %v\n%s", name, err, text)
		}
	}
}
