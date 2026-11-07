// Package profile checks the repository profile, .loupe/lifecycle.md. Every
// stage skill reads that file, and a stage stops when it is missing. An agent
// writes it with the owner, through the loupe-profile skill.
package profile

import (
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
)

// Path is the profile, relative to the repository root.
const Path = ".loupe/lifecycle.md"

// OpenQuestion stands in the file for a value that the owner has to give. A
// stage that needs the value stops blocked.
const OpenQuestion = "owner to fill in"

// Sections are the headings that every profile holds.
var Sections = []string{
	"Instruction files", "Environment", "Gate", "Code review",
	"Pull request", "Board", "Merge", "Recovery",
}

// Status says what is left to do for the profile of a repository.
type Status struct {
	// Missing is true when the file does not exist.
	Missing bool
	// MissingSections lists the headings the file lacks.
	MissingSections []string
	// RenameRepair is true when the file holds the old heading Repair and not
	// Recovery. The heading only needs a rename.
	RenameRepair bool
	// OpenValues counts the lines that hold OpenQuestion.
	OpenValues int
}

// Complete reports whether the file needs no agent.
func (s Status) Complete() bool {
	return !s.Missing && len(s.MissingSections) == 0 && s.OpenValues == 0
}

// Reason is the short text for the step line of init.
func (s Status) Reason() string {
	switch {
	case s.Missing:
		return Path + " is missing"
	case s.RenameRepair:
		return Path + " names the Recovery section Repair. Rename the heading ## Repair to ## Recovery"
	case len(s.MissingSections) > 0:
		return Path + " lacks the section " + strings.Join(s.MissingSections, ", ")
	case s.OpenValues > 0:
		return Path + " holds a value marked \"" + OpenQuestion + "\""
	}

	return Path + " is complete"
}

// Check reads the profile in dir.
func Check(dir string) (Status, error) {
	raw, err := os.ReadFile(filepath.Join(dir, Path))
	if errors.Is(err, os.ErrNotExist) {
		return Status{Missing: true}, nil
	}
	if err != nil {
		return Status{}, err
	}
	text := string(raw)

	var s Status
	for _, name := range Sections {
		if !hasHeading(text, name) {
			s.MissingSections = append(s.MissingSections, name)
		}
	}
	s.RenameRepair = slices.Contains(s.MissingSections, "Recovery") && hasHeading(text, "Repair")
	s.OpenValues = strings.Count(text, OpenQuestion)

	return s, nil
}

// hasHeading reports whether text holds a ## heading with exactly this name.
func hasHeading(text, name string) bool {
	return regexp.MustCompile(`(?m)^##\s+` + regexp.QuoteMeta(name) + `\s*$`).MatchString(text)
}
