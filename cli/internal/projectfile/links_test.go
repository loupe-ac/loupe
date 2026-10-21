package projectfile

import (
	"os"
	"os/exec"
	"path/filepath"
	"testing"
)

const linkProject = "01a0c0e1-1111-7922-a586-ccc8ce043705"

func TestLinksRoundTripAndReplace(t *testing.T) {
	setup := filepath.Join(t.TempDir(), "setup")
	if got, err := LoadLink(setup, "/k"); err != nil || got != "" {
		t.Fatalf("empty setup: %q, %v", got, err)
	}
	if err := WriteLink(setup, "/k", linkProject); err != nil {
		t.Fatal(err)
	}
	if err := WriteLink(setup, "/other", "01a0c0e1-1111-7922-a586-ccc8ce043706"); err != nil {
		t.Fatal(err)
	}
	if got, _ := LoadLink(setup, "/k"); got != linkProject {
		t.Fatalf("link = %q", got)
	}
	if err := WriteLink(setup, "/k", "not-an-id"); err == nil {
		t.Fatal("an invalid id must be refused")
	}
}

func TestLinkKeyIsOneForAllWorktreesOfARepository(t *testing.T) {
	if _, err := exec.LookPath("git"); err != nil {
		t.Skip("git is not installed")
	}
	root := t.TempDir()
	repo := filepath.Join(root, "repo")
	if err := os.MkdirAll(filepath.Join(repo, "sub"), 0o755); err != nil {
		t.Fatal(err)
	}
	git := func(args ...string) {
		t.Helper()
		cmd := exec.Command("git", append([]string{"-C", repo, "-c", "user.email=a@b.c", "-c", "user.name=n"}, args...)...)
		if out, err := cmd.CombinedOutput(); err != nil {
			t.Fatalf("git %v: %v\n%s", args, err, out)
		}
	}
	git("init", "-q")
	git("commit", "-q", "--allow-empty", "-m", "x")
	work := filepath.Join(root, "work tree")
	git("worktree", "add", "-q", "-b", "wt", work)

	main := LinkKey(repo)
	if got := LinkKey(filepath.Join(repo, "sub")); got != main {
		t.Errorf("subfolder key %q != %q", got, main)
	}
	if got := LinkKey(work); got != main {
		t.Errorf("worktree key %q != %q", got, main)
	}

	plain := t.TempDir()
	if got := LinkKey(plain); got == main || filepath.Base(got) != filepath.Base(plain) {
		t.Errorf("a folder outside git keys itself, got %q", got)
	}
}
