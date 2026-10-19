package profile

import (
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// Command is one gate command with the file that made the guess.
type Command struct {
	Run    string
	Source string
}

// Detection is what a repository shows about itself. Every field is a guess.
type Detection struct {
	Gate       []Command
	Base       string
	BaseFound  bool
	Changelog  string
	AgentFiles []string
}

// gateNames are the task names that a gate runs, in the order it runs them: a
// formatter first, then static checks, then tests.
var gateNames = []string{"cs", "format", "fmt", "lint", "typecheck", "phpstan", "check", "test"}

var agentFiles = []string{"AGENTS.md", "CLAUDE.md"}

var changelogFiles = []string{"CHANGELOG.md", "docs/CHANGELOG.md"}

// Detect reads dir and reports what it finds. It runs no project command.
func Detect(dir string) Detection {
	d := Detection{Base: "main"}

	d.Gate = taskRunnerGate(dir)
	if len(d.Gate) == 0 {
		d.Gate = append(d.Gate, packageGate(dir)...)
		d.Gate = append(d.Gate, composerGate(dir)...)
		d.Gate = append(d.Gate, goGate(dir)...)
	}

	if base, ok := baseBranch(dir); ok {
		d.Base, d.BaseFound = base, true
	}
	for _, name := range changelogFiles {
		if exists(filepath.Join(dir, name)) {
			d.Changelog = name

			break
		}
	}
	for _, name := range agentFiles {
		if exists(filepath.Join(dir, name)) {
			d.AgentFiles = append(d.AgentFiles, name)
		}
	}

	return d
}

// taskRunnerGate prefers a justfile or a Makefile. A project that wraps its
// checks in a task runner names the full gate there, and its own manifests
// would repeat it.
func taskRunnerGate(dir string) []Command {
	if names := recipes(dir, "justfile", justRecipe); len(names) > 0 {
		return pick(names, "justfile", func(n string) string { return "just " + n })
	}
	if names := recipes(dir, "Makefile", makeTarget); len(names) > 0 {
		return pick(names, "Makefile", func(n string) string { return "make " + n })
	}

	return nil
}

var (
	justRecipe = regexp.MustCompile(`^@?([A-Za-z][\w-]*)(?:\s+[^:=]*)?:(?:[^=]|$)`)
	makeTarget = regexp.MustCompile(`^([A-Za-z][\w-]*)\s*:(?:[^=]|$)`)
)

// recipes lists the task names a file defines. A recipe that needs a parameter
// is not a gate command, so a name with parameters is skipped.
func recipes(dir, file string, pattern *regexp.Regexp) map[string]bool {
	raw, err := os.ReadFile(filepath.Join(dir, file))
	if err != nil {
		return nil
	}

	found := map[string]bool{}
	for _, line := range strings.Split(string(raw), "\n") {
		if m := pattern.FindStringSubmatch(line); m != nil {
			if "justfile" == file && strings.Contains(strings.SplitN(line, ":", 2)[0], " ") {
				continue
			}
			found[m[1]] = true
		}
	}

	return found
}

// pick keeps the gate names a project defines, in gate order.
func pick(names map[string]bool, source string, run func(string) string) []Command {
	var out []Command
	for _, name := range gateNames {
		if names[name] {
			out = append(out, Command{Run: run(name), Source: source})
		}
	}

	return out
}

func packageGate(dir string) []Command {
	var manifest struct {
		Scripts map[string]string `json:"scripts"`
	}
	if !readJSON(filepath.Join(dir, "package.json"), &manifest) {
		return nil
	}

	names := map[string]bool{}
	for name := range manifest.Scripts {
		names[name] = true
	}
	runner := nodeRunner(dir)

	return pick(names, "package.json", func(n string) string {
		if "test" == n && "npm" == runner {
			return "npm test"
		}

		return runner + " run " + n
	})
}

// nodeRunner reads the lockfile, because the wrong package manager rewrites it.
func nodeRunner(dir string) string {
	switch {
	case exists(filepath.Join(dir, "pnpm-lock.yaml")):
		return "pnpm"
	case exists(filepath.Join(dir, "yarn.lock")):
		return "yarn"
	case exists(filepath.Join(dir, "bun.lock")), exists(filepath.Join(dir, "bun.lockb")):
		return "bun"
	}

	return "npm"
}

func composerGate(dir string) []Command {
	var manifest struct {
		Scripts map[string]json.RawMessage `json:"scripts"`
	}
	if !readJSON(filepath.Join(dir, "composer.json"), &manifest) {
		return nil
	}

	names := map[string]bool{}
	for name := range manifest.Scripts {
		names[name] = true
	}

	return pick(names, "composer.json", func(n string) string { return "composer run " + n })
}

func goGate(dir string) []Command {
	if !exists(filepath.Join(dir, "go.mod")) {
		return nil
	}

	return []Command{
		{Run: "go vet ./...", Source: "go.mod"},
		{Run: "go test ./...", Source: "go.mod"},
	}
}

// baseBranch reads the default branch that the clone recorded for its remote,
// from .git/refs/remotes/origin/HEAD. It runs no git command and makes no
// network call. It reports false when the clone recorded none, or when .git is
// a file, as in a worktree.
func baseBranch(dir string) (string, bool) {
	raw, err := os.ReadFile(filepath.Join(dir, ".git", "refs", "remotes", "origin", "HEAD"))
	if err != nil {
		return "", false
	}

	name := strings.TrimPrefix(strings.TrimSpace(string(raw)), "ref: refs/remotes/origin/")
	if "" == name || strings.HasPrefix(name, "ref:") {
		return "", false
	}

	return name, true
}

func readJSON(path string, into any) bool {
	raw, err := os.ReadFile(path)
	if err != nil {
		return false
	}

	return nil == json.Unmarshal(raw, into)
}

func exists(path string) bool {
	_, err := os.Stat(path)

	return nil == err
}
