// Package profile writes the starter repository profile, .loupe/lifecycle.md.
// Every stage skill reads that file, and a stage stops when it is missing.
package profile

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// Path is the profile, relative to the repository root.
const Path = ".loupe/lifecycle.md"

// Marker follows every value that the starter guessed. Discovery looks for it.
const Marker = "detected, check it"

// Render builds the profile text for what Detect found.
func Render(d Detection) string {
	var b strings.Builder

	b.WriteString("# Lifecycle profile\n\n")
	b.WriteString("The Loupe stage skills read this file. It holds the values that belong to this repository.\n")
	b.WriteString("`loupe init` wrote the first version. A value marked \"" + Marker + "\" is a guess, so read it before you commit this file.\n\n")

	b.WriteString("## Instruction files\n\n")
	if len(d.AgentFiles) == 0 {
		b.WriteString("1. This repository has no agent instruction file (" + Marker + "). Add an `AGENTS.md` and name it here.\n\n")
	} else {
		for i, name := range d.AgentFiles {
			fmt.Fprintf(&b, "%d. Read `%s`, and follow it.\n", i+1, name)
		}
		b.WriteString("\n")
	}

	b.WriteString("## Environment\n\n")
	b.WriteString("1. The worker starts in the folder that the bridge makes for the card. A stage never creates or removes it.\n")
	b.WriteString("2. This profile names no folder check and no refresh command, so a stage runs none.\n\n")

	b.WriteString("## Gate\n\n")
	fmt.Fprintf(&b, "1. Base branch: `%s`%s.\n", d.Base, guess(d.BaseFound, "from the remote", "the remote names none"))
	if len(d.Gate) == 0 {
		b.WriteString("2. No gate command found (" + Marker + "). Write the commands that must pass before a push, one per line.\n")
	}
	for i, c := range d.Gate {
		fmt.Fprintf(&b, "%d. Run `%s` (%s: %s).\n", i+2, c.Run, Marker, c.Source)
	}
	fmt.Fprintf(&b, "%d. The starter lists only checks with a common name, such as `lint` or `test`, so this list can miss one (%s). Add each other check that CI runs and that must pass before a push.\n", max(len(d.Gate), 1)+2, Marker)
	b.WriteString("\nFix every failure of the gate, also one that pre-dates the branch.\n\n")

	b.WriteString("## Code review\n\n")
	b.WriteString("1. Run no review before the push. A separate review worker reviews the draft pull request after the push.\n")
	b.WriteString("2. Read the largest change of the diff yourself before you push.\n\n")

	b.WriteString("## Changelog\n\n")
	if d.Changelog == "" {
		b.WriteString("1. This repository keeps no changelog (" + Marker + "). A stage writes no entry.\n\n")
	} else {
		fmt.Fprintf(&b, "1. Add one line for the change to `%s` (%s).\n\n", d.Changelog, Marker)
	}

	b.WriteString("## Pull request\n\n")
	b.WriteString("1. Open it as a draft, against the base branch of the `Gate` section.\n")
	b.WriteString("2. Write the title as `<type>(<area>): <summary>`.\n")
	b.WriteString("3. Keep the body short. Say what changed and why, and link the card.\n")
	b.WriteString("4. Never mark it ready. The workflow marks it ready when the review passed and the checks are green.\n")
	b.WriteString("5. Only the merge stage merges it. Never use `--admin` or `--no-verify`.\n\n")

	b.WriteString("## Board\n\n")
	b.WriteString("1. The column that holds a pull request waiting for review is `in-review`. The app moves a card there when the checks pass, and to `done` after the merge.\n")
	b.WriteString("2. No stage moves a card to `in-review` or `done`.\n")
	b.WriteString("3. The column that holds a card in product design is `product-design`.\n")
	b.WriteString("4. The column that holds a card in implementation is `implementation`.\n")
	b.WriteString("5. The default column is `backlog`, and the terminal column is `done`.\n\n")

	b.WriteString("## Merge\n\n")
	b.WriteString("1. The merge method is `squash` (" + Marker + ").\n")
	b.WriteString("2. Merge only a pull request whose base is the base branch of the `Gate` section.\n")
	b.WriteString("3. Count the approval of any reviewer (" + Marker + "). Name one approver here to count that reviewer alone.\n")
	b.WriteString("4. An epic child merges as the `Epics` section says.\n\n")

	b.WriteString("## Epics\n\n")
	b.WriteString("1. The epic branch of epic card `<n>` is `epic/<n>` (" + Marker + "). It must match the `epicBranch` value of the workflow template of the project, which is `epic/{number}` in both shipped templates.\n")
	b.WriteString("2. The breakdown pushes the epic branch from the base branch of the `Gate` section.\n")
	b.WriteString("3. A child of an epic whose `epic/<n>` branch exists cuts its branch from `origin/epic/<n>`, and its pull request targets `epic/<n>`. An epic with no such branch keeps the flow of the base branch for its children.\n")
	b.WriteString("4. A child merges into `epic/<n>` with `squash` and no approval, once its checks pass.\n")
	b.WriteString("5. The epic pull request goes from `epic/<n>` to the base branch, and merges as the `Merge` section says. Nothing force-pushes `epic/<n>`.\n")
	b.WriteString("6. This profile names no epic preview and no epic preview lock (" + Marker + "), so a refresh of the epic preview stops blocked. Describe here how to serve the epic branch.\n\n")

	b.WriteString("## Repair\n\n")
	b.WriteString("1. The repair worker starts in the folder the bridge makes for the card. Run no project command there.\n")
	b.WriteString("2. Before each action, `git status --porcelain` must print nothing. Otherwise stop blocked.\n")
	b.WriteString("3. The worker may reset or re-create the card branch from its base. It stops when the branch holds a commit that the base lacks.\n")
	b.WriteString("4. The worker never pushes to the base branch, never force-pushes, and never changes application code.\n")

	return b.String()
}

func guess(found bool, because, otherwise string) string {
	if found {
		return " (" + Marker + ": " + because + ")"
	}

	return " (" + Marker + ": " + otherwise + ")"
}

// Result says what Write did.
type Result int

const (
	// Written means the starter profile now exists.
	Written Result = iota
	// Existing means a profile was there already, and Write left it alone.
	Existing
)

// Write puts the starter profile in dir. A profile that exists is somebody's
// work, so Write never replaces it.
func Write(dir string) (Result, error) {
	path := filepath.Join(dir, Path)
	if _, err := os.Stat(path); err == nil {
		return Existing, nil
	} else if !errors.Is(err, os.ErrNotExist) {
		return Existing, err
	}

	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return Existing, err
	}
	// O_EXCL keeps a profile that appeared since the check.
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o644)
	if err != nil {
		if errors.Is(err, os.ErrExist) {
			return Existing, nil
		}

		return Existing, err
	}
	defer f.Close()

	if _, err := f.WriteString(Render(Detect(dir))); err != nil {
		return Existing, err
	}

	return Written, nil
}
