package cmd

import (
	"path/filepath"
	"time"

	harn "github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/usage"
)

// usageFiler is a harness that keeps the limits of its account in a file
// apart from the output of the run, as Codex does in its session file.
type usageFiler interface {
	UsageFile(runDir string) (string, bool)
}

// usageTail follows the file of the run in dir that holds the usage of its
// account. A reading older than since is the reading of an earlier run of a
// session file that the run resumed.
func usageTail(h harn.Harness, dir string, since time.Time) *usage.Tail {
	filer, ok := h.(usageFiler)
	if !ok {
		path := filepath.Join(dir, "stdout")

		return usage.NewTail(func() (string, bool) { return path, true }, usage.ParseClaude)
	}
	var found string
	path := func() (string, bool) {
		if found == "" {
			found, _ = filer.UsageFile(dir)
		}

		return found, found != ""
	}
	parse := func(line []byte, now time.Time) []usage.Reading {
		var out []usage.Reading
		for _, r := range usage.ParseCodex(line, now) {
			if !r.ReadAt.Before(since) {
				out = append(out, r)
			}
		}

		return out
	}

	return usage.NewTail(path, parse)
}

// followUsage starts to read the usage of the account of a worker run. A nil
// collector, and a run of no account, read nothing.
func (r *router) followUsage(p pending, dir string, since time.Time) {
	if r.accountUsage == nil || dir == "" || p.spec.account == "" {
		return
	}
	r.accountUsage.Start(p.runID, p.spec.account, usageTail(p.spec.adapter(), dir, since))
}
