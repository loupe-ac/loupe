package installscript

import (
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

// signRepo makes a clone with a main branch and a side branch. It returns the
// folder, the commit on main, and a commit that is not on main.
func signRepo(t *testing.T) (dir, onMain, offMain string) {
	t.Helper()
	for _, tool := range []string{"git", "jq"} {
		if _, err := exec.LookPath(tool); err != nil {
			t.Skipf("%s is not available", tool)
		}
	}
	dir = t.TempDir()
	git := func(args ...string) string {
		t.Helper()
		cmd := exec.Command("git", append([]string{"-c", "user.name=t", "-c", "user.email=t@example.com", "-c", "commit.gpgsign=false"}, args...)...)
		cmd.Dir = dir
		out, err := cmd.CombinedOutput()
		if err != nil {
			t.Fatalf("git %v: %v\n%s", args, err, out)
		}

		return strings.TrimSpace(string(out))
	}
	git("init", "-q", "-b", "main")
	git("commit", "-q", "--allow-empty", "-m", "one")
	onMain = git("rev-parse", "HEAD")
	git("checkout", "-q", "-b", "attacker")
	git("commit", "-q", "--allow-empty", "-m", "two")
	offMain = git("rev-parse", "HEAD")
	git("checkout", "-q", "main")

	return dir, onMain, offMain
}

const (
	cliWorkflow = "cli-release.yml"
	cliRef      = "refs/tags/cli/v1.0.0"
)

// checkSign runs sign-release-check.sh on the fixture after the replacements.
func checkSign(t *testing.T, dir, workflow, ref string, replace map[string]string) (string, error) {
	t.Helper()
	script, err := filepath.Abs("../scripts/sign-release-check.sh")
	if err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile("../scripts/testdata/attestation.json")
	if err != nil {
		t.Fatal(err)
	}
	doc := string(raw)
	// Longer patterns first, so a specific replacement runs before the general one.
	froms := make([]string, 0, len(replace))
	for from := range replace {
		froms = append(froms, from)
	}
	sort.Slice(froms, func(i, j int) bool { return len(froms[i]) > len(froms[j]) })
	for _, from := range froms {
		to := replace[from]
		if !strings.Contains(doc, from) {
			t.Fatalf("the fixture has no %q", from)
		}
		doc = strings.ReplaceAll(doc, from, to)
	}
	file := filepath.Join(t.TempDir(), "attestation.json")
	if err := os.WriteFile(file, []byte(doc), 0o644); err != nil {
		t.Fatal(err)
	}
	cmd := exec.Command("sh", script, file, workflow, ref, "main")
	cmd.Dir = dir
	out, err := cmd.CombinedOutput()

	return string(out), err
}

func TestSignReleaseAcceptsABuildFromMain(t *testing.T) {
	dir, onMain, _ := signRepo(t)
	out, err := checkSign(t, dir, cliWorkflow, cliRef, map[string]string{"@COMMIT@": onMain})
	if err != nil {
		t.Fatalf("the check refused a good attestation: %v\n%s", err, out)
	}
	if strings.TrimSpace(out) != onMain {
		t.Errorf("the check printed %q, want the commit %s", out, onMain)
	}
}

func TestSignReleaseAcceptsASkillsBuildOnMain(t *testing.T) {
	dir, onMain, _ := signRepo(t)
	out, err := checkSign(t, dir, "skills-release.yml", "refs/heads/main", map[string]string{
		"@COMMIT@":             onMain,
		"cli-release.yml":      "skills-release.yml",
		"refs/tags/cli/v1.0.0": "refs/heads/main",
	})
	if err != nil {
		t.Fatalf("the check refused a good attestation: %v\n%s", err, out)
	}
}

func TestSignReleaseRefuses(t *testing.T) {
	dir, onMain, offMain := signRepo(t)
	cases := map[string]struct {
		workflow string
		ref      string
		replace  map[string]string
		want     string
	}{
		"a wrong workflow": {
			workflow: cliWorkflow, ref: cliRef,
			replace: map[string]string{"@COMMIT@": onMain, "workflows/cli-release.yml@": "workflows/other.yml@"},
			want:    "signer workflow",
		},
		"a wrong ref": {
			workflow: cliWorkflow, ref: "refs/tags/cli/v1.0.1",
			replace: map[string]string{"@COMMIT@": onMain},
			want:    "signer workflow",
		},
		"a wrong source ref": {
			workflow: cliWorkflow, ref: cliRef,
			replace: map[string]string{"@COMMIT@": onMain, `"sourceRepositoryRef": "refs/tags/cli/v1.0.0"`: `"sourceRepositoryRef": "refs/heads/evil"`},
			want:    "source ref",
		},
		"a wrong repository": {
			workflow: cliWorkflow, ref: cliRef,
			replace: map[string]string{"@COMMIT@": onMain, `"githubWorkflowRepository": "loupe-ac/loupe"`: `"githubWorkflowRepository": "evil/loupe"`},
			want:    "repository",
		},
		"a self-hosted runner": {
			workflow: cliWorkflow, ref: cliRef,
			replace: map[string]string{"@COMMIT@": onMain, "github-hosted": "self-hosted"},
			want:    "runner",
		},
		"differing source and signer commits": {
			workflow: cliWorkflow, ref: cliRef,
			replace: map[string]string{`"buildSignerDigest": "@COMMIT@"`: `"buildSignerDigest": "` + offMain + `"`, "@COMMIT@": onMain},
			want:    "differs",
		},
		"a commit that is not an ancestor of main": {
			workflow: cliWorkflow, ref: cliRef,
			replace: map[string]string{"@COMMIT@": offMain},
			want:    "not an ancestor",
		},
		"a commit that git does not know": {
			workflow: cliWorkflow, ref: cliRef,
			replace: map[string]string{"@COMMIT@": strings.Repeat("a", 40)},
			want:    "not an ancestor",
		},
		"a commit that is no commit id": {
			workflow: cliWorkflow, ref: cliRef,
			replace: map[string]string{"@COMMIT@": "main"},
			want:    "not a commit id",
		},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			out, err := checkSign(t, dir, c.workflow, c.ref, c.replace)
			if err == nil {
				t.Fatalf("the check accepted %s:\n%s", name, out)
			}
			assertContains(t, out, "refused", c.want)
		})
	}
}

func TestSignReleaseRefusesEmptyAndBrokenOutput(t *testing.T) {
	dir, _, _ := signRepo(t)
	script, err := filepath.Abs("../scripts/sign-release-check.sh")
	if err != nil {
		t.Fatal(err)
	}
	for name, body := range map[string]string{"an empty list": "[]", "no JSON": "not json", "an object": "{}"} {
		t.Run(name, func(t *testing.T) {
			file := filepath.Join(t.TempDir(), "attestation.json")
			if err := os.WriteFile(file, []byte(body), 0o644); err != nil {
				t.Fatal(err)
			}
			cmd := exec.Command("sh", script, file, cliWorkflow, cliRef, "main")
			cmd.Dir = dir
			out, err := cmd.CombinedOutput()
			if err == nil {
				t.Fatalf("the check accepted %s:\n%s", name, out)
			}
			assertContains(t, string(out), "refused")
		})
	}
}
