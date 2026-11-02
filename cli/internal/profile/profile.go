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

// OpenQuestion takes the place of a value that a stage needs, that the repository
// does not show and that Loupe does not define. A stage that needs the value stops
// blocked, and discovery asks the owner for it.
const OpenQuestion = "owner to fill in"

// Render builds the profile text for what Detect found. It holds what a stage
// reads, and states no policy of a repository as fact.
func Render(d Detection) string {
	var b strings.Builder

	b.WriteString("# Lifecycle profile\n\n")
	b.WriteString("The Loupe stage skills read this file. It holds the values that belong to this repository.\n")
	b.WriteString("`loupe project link` wrote the first version. A value marked \"" + Marker + "\" is a guess, so read it before you commit this file. A line marked \"" + OpenQuestion + "\" holds no value yet, and a stage that needs the value stops blocked.\n\n")

	b.WriteString("## Instruction files\n\n")
	next := 1
	if len(d.AgentFiles) == 0 {
		b.WriteString("1. No agent instruction file found (" + Marker + "). Name each file that a worker must read here.\n")
		next = 2
	} else {
		for i, name := range d.AgentFiles {
			fmt.Fprintf(&b, "%d. Read `%s`, and follow it (%s).\n", i+1, name, Marker)
			next = i + 2
		}
	}
	fmt.Fprintf(&b, "%d. This profile names no writing style, no document checks, no tech design instructions, no deploy items and no design system (%s). Add a line for each one that this repository has.\n\n", next, Marker)

	b.WriteString("## Environment\n\n")
	b.WriteString("1. Folder check: none (" + Marker + "). Name a command here when a worker must prove that it is not in the main checkout.\n\n")

	b.WriteString("## Gate\n\n")
	fmt.Fprintf(&b, "1. Base branch: `%s`%s.\n", d.Base, guess(d.BaseFound, "from the remote", "the remote names none"))
	if len(d.Gate) == 0 {
		b.WriteString("2. No gate command found (" + Marker + "). Write the commands that must pass before a push, one per line.\n")
	}
	for i, c := range d.Gate {
		fmt.Fprintf(&b, "%d. Run `%s` (%s: %s).\n", i+2, c.Run, Marker, c.Source)
	}
	item := max(len(d.Gate), 1) + 2
	fmt.Fprintf(&b, "%d. The starter lists only checks with a common name, such as `lint` or `test`, so this list can miss one (%s). Add each other check that CI runs and that must pass before a push.\n", item, Marker)
	fmt.Fprintf(&b, "%d. Required checks: %s. Name the checks that the rules of the base branch require, or say how to read them. The merge stage counts them.\n\n", item+1, OpenQuestion)

	b.WriteString("## Code review\n\n")
	b.WriteString("1. Review before the push: none (" + Marker + "). Name a review step and its pass rule here when this repository has one.\n\n")

	b.WriteString("## Changelog\n\n")
	if d.Changelog == "" {
		b.WriteString("1. Changelog: none (" + Marker + "). Name the file and its check here when this repository keeps one.\n\n")
	} else {
		fmt.Fprintf(&b, "1. Add one line for the change to `%s` (%s).\n\n", d.Changelog, Marker)
	}

	b.WriteString("## Pull request\n\n")
	b.WriteString("1. Open it as a draft, against the base branch of the `Gate` section, and never mark it ready (" + Marker + ": the Lifecycle workflow marks a draft ready after its agent review).\n")
	b.WriteString("2. Write the title as a one-line summary of the change (" + Marker + "). Name a format here when this repository has one.\n\n")

	b.WriteString("## Board\n\n")
	b.WriteString("Each slug is a default of the Lifecycle workflow. `workflow_get` lists the columns of the project.\n\n")
	b.WriteString("1. The column that holds a pull request waiting for review is `in-review` (" + Marker + ").\n")
	b.WriteString("2. The column that holds a card in product design is `product-design` (" + Marker + ").\n")
	b.WriteString("3. The column that holds a card in tech design is `tech-design` (" + Marker + ").\n")
	b.WriteString("4. The column that holds a card in implementation is `implementation` (" + Marker + ").\n")
	b.WriteString("5. The default column is `backlog`, and the terminal column is `done` (" + Marker + ").\n\n")

	b.WriteString("## Merge\n\n")
	b.WriteString("1. The merge method: " + OpenQuestion + ". Write a method that the rules of the base branch allow. A worker reads it only when the forge has no connection that writes the merge.\n")
	b.WriteString("2. The base branch is the base branch of the `Gate` section (" + Marker + ").\n")
	b.WriteString("3. This profile names no approver (" + Marker + "). Name one here to also require the approval of that reviewer.\n")
	b.WriteString("4. An epic child merges as the `Epics` section says.\n\n")

	b.WriteString("## Epics\n\n")
	b.WriteString("1. The epic branch of epic card `<n>` is `epic/<n>` (" + Marker + "). It must match the `epicBranch` value that `workflow_get` returns.\n")
	b.WriteString("2. The merge method of an epic child: " + OpenQuestion + ". Write a method that the epic branch allows.\n")
	b.WriteString("3. This profile names no epic preview, no preview link form and no shape for a value from a pull request body (" + Marker + "), so a refresh of the epic preview stops blocked. Describe them here when this repository serves an epic branch.\n\n")

	b.WriteString("## Repair\n\n")
	b.WriteString("1. Before each action, `git status --porcelain` must print nothing (" + Marker + "). Otherwise stop blocked.\n")
	b.WriteString("2. Remove a broken worker folder with `loupe env reset`, only when the `repair` work entry of the rule file sets `reset` (" + Marker + "). With no `reset`, the command exits 0 and removes nothing, so stop blocked when the folder stays.\n")
	b.WriteString("3. Sync the epic branch of the parent card, as `loupe-stage-merge` \"Sync the epic branch\" says (" + Marker + ").\n")
	b.WriteString("4. Other actions: " + OpenQuestion + ". Write each action with its commands and its guards. The worker takes no action that this list does not name.\n")

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
