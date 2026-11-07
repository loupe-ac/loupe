package rules

import (
	"errors"
	"fmt"
	"maps"
	"os"
	"path/filepath"
	"slices"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"go.yaml.in/yaml/v3"
)

// checkProjectWork checks the work entries of one project as it checks the
// shared ones, and names the project in each error. A command entry stays in
// the shared map, because the heartbeat reports command entries with no
// project.
func (s *Set) checkProjectWork(slug string, p Project, declaredPools map[string]WorkerPool, pools map[string]int, known, declared []string, root *yaml.Node) []error {
	var errs []error
	for _, kind := range slices.Sorted(maps.Keys(p.Work)) {
		w := p.Work[kind]
		label := fmt.Sprintf("project %q work %q", slug, kind)
		if err := checkWork(kind, &w); err != nil {
			errs = append(errs, fmt.Errorf("%s: %w", label, err))
		}
		if w.Action == ActionCommand {
			errs = append(errs, fmt.Errorf("%s: action %s is not allowed under a project, because the heartbeat reports command entries with no project; list it under work", label, ActionCommand))
		}
		errs = append(errs, s.checkEntryAccounts([]any{"projects", slug, "work", kind}, label+": ", &w, declared, root)...)
		if w.Action == "" {
			if err := checkPool(w.WorkerPool, declaredPools, pools, known, s.maxWorkers); err != nil {
				errs = append(errs, fmt.Errorf("%s: %w", label, err))
			}
			// A nil map of fields always builds.
			w.schema, _ = resultSchema(nil)
		}
		if s.projectWork[slug] == nil {
			s.projectWork[slug] = map[string]WorkEntry{}
		}
		s.projectWork[slug][kind] = w
	}

	return errs
}

// entryFor is the effective work entry of a kind for a project: the entry of
// the project, else the shared entry. Every path that reads an entry for a
// request goes through it. An empty slug reads the shared entry.
func (s *Set) entryFor(slug, kind string) (WorkEntry, bool) {
	if w, ok := s.projectWork[slug][kind]; ok {
		return w, true
	}
	if w, ok := s.coveredWork[slug][kind]; ok {
		return w, true
	}
	w, ok := s.work[kind]

	return w, ok
}

// allEntries lists the shared entries, then the entries of each project, in
// kind order. The bridge-wide lists read it.
func (s *Set) allEntries() []WorkEntry {
	var out []WorkEntry
	for _, kind := range slices.Sorted(maps.Keys(s.work)) {
		out = append(out, s.work[kind])
	}
	for _, slug := range s.workSlugs() {
		for _, kind := range slices.Sorted(maps.Keys(s.projectWork[slug])) {
			out = append(out, s.projectWork[slug][kind])
		}
		for _, kind := range slices.Sorted(maps.Keys(s.coveredWork[slug])) {
			out = append(out, s.coveredWork[slug][kind])
		}
	}

	return out
}

// workSlugs lists the projects that hold an entry of their own or a covered
// copy, in name order. A project with only covered copies has no projectWork
// key, so the bridge-wide lists must read both maps.
func (s *Set) workSlugs() []string {
	out := slices.AppendSeq(slices.Collect(maps.Keys(s.projectWork)), maps.Keys(s.coveredWork))
	slices.Sort(out)

	return slices.Compact(out)
}

// entriesOf lists every entry of a kind: the shared one, then one for each
// project that lists the kind.
func (s *Set) entriesOf(kind string) []WorkEntry {
	var out []WorkEntry
	if w, ok := s.work[kind]; ok {
		out = append(out, w)
	}
	for _, slug := range s.workSlugs() {
		if w, ok := s.projectWork[slug][kind]; ok {
			out = append(out, w)
		}
		if w, ok := s.coveredWork[slug][kind]; ok {
			out = append(out, w)
		}
	}

	return out
}

// allKinds lists the kinds that the shared map or any project lists, in name
// order. A kind that only one project lists still counts, because the bridge
// reports one list for all its projects.
func (s *Set) allKinds() []string {
	kinds := maps.Clone(s.work)
	var out []string
	out = slices.AppendSeq(out, maps.Keys(kinds))
	for _, entries := range s.projectWork {
		out = slices.AppendSeq(out, maps.Keys(entries))
	}
	slices.Sort(out)

	return slices.Compact(out)
}

// SharedWorkKinds lists the kinds of the shared map, in name order.
func (s *Set) SharedWorkKinds() []string {
	return slices.Sorted(maps.Keys(s.work))
}

// SharedWorkEntry is the shared entry of a kind.
func (s *Set) SharedWorkEntry(kind string) (WorkEntry, bool) {
	w, ok := s.work[kind]

	return w, ok
}

// ProjectWorkEntry is the entry that a project lists itself.
func (s *Set) ProjectWorkEntry(slug, kind string) (WorkEntry, bool) {
	w, ok := s.projectWork[slug][kind]

	return w, ok
}

// WorkEntryFor is the effective entry of a kind for a project.
func (s *Set) WorkEntryFor(slug, kind string) (WorkEntry, bool) {
	return s.entryFor(slug, kind)
}

// ProjectWorkKinds lists the kinds a project lists itself, in name order.
func (s *Set) ProjectWorkKinds(slug string) []string {
	return slices.Sorted(maps.Keys(s.projectWork[slug]))
}

// Warnings lists what a valid rule file still gets wrong, one line each, in
// project order. It reads the disk and makes no request.
//
//   - A relative before, refresh, reset or command that names a file the
//     project folder lacks.
//   - A kind that another project lists and this project does not.
//   - A project entry of a kind that a top-level experiment covers, which
//     runs without the variants.
//   - A project experiment that covers a kind a top-level experiment also
//     covers.
func (s *Set) Warnings() []string {
	var out []string
	for _, slug := range s.Projects() {
		dir := s.dirs[slug]
		for _, kind := range s.allKinds() {
			entry, ok := s.entryFor(slug, kind)
			if !ok {
				if kind == TeardownKind && s.Isolation() == IsolationWorktree {
					continue
				}
				fate := "is skipped, and another bridge can take it"
				if s.appPrompts {
					fate = "runs the app prompt"
				}
				out = append(out, fmt.Sprintf("project %s: lists no work entry %s, which another project lists; a request of this kind for it %s", slug, kind, fate))

				continue
			}
			for _, c := range entry.commands() {
				if missing := missingFile(dir, c.argv); missing != "" {
					out = append(out, fmt.Sprintf("project %s: work %s %s runs %s, which does not exist in %s", slug, kind, c.field, c.argv[0], dir))
				}
			}
			shared := s.work[kind].experiment
			own := s.projectWork[slug][kind].experiment
			if own == nil {
				own = s.coveredWork[slug][kind].experiment
			}
			switch {
			case own != nil && shared != nil:
				out = append(out, fmt.Sprintf("project %s: experiment %q covers %s for this project, so its runs leave the top-level experiment %q", slug, own.Name, kind, shared.Name))
			case own == nil && shared != nil:
				if _, listed := s.projectWork[slug][kind]; listed {
					out = append(out, fmt.Sprintf("project %s: work %s runs without the variants of experiment %q, which covers the shared entry only; add an experiments: block under the project to run a trial on it", slug, kind, shared.Name))
				}
			}
		}
	}

	return out
}

// EntryCommand is one command that a work entry runs in the project folder.
type EntryCommand struct {
	Field string
	Argv  []string
}

// Commands lists the commands the entry runs: before, refresh, reset and the
// run of a command entry.
func (w WorkEntry) Commands() []EntryCommand {
	var out []EntryCommand
	for _, c := range w.commands() {
		out = append(out, EntryCommand{Field: c.field, Argv: slices.Clone(c.argv)})
	}

	return out
}

// RunSettings gives the account settings of an agent entry, and the zero value
// for an entry that runs no agent.
func (w WorkEntry) RunSettings() RunSettings {
	return w.run.clone()
}

// Runs lists the account settings the entry can run with: its own, then those
// of each variant of its experiment. An entry that runs no agent lists none.
func (w WorkEntry) Runs() []RunSettings {
	var out []RunSettings
	for _, r := range w.runs() {
		out = append(out, r.clone())
	}

	return out
}

// AppRun gives the account settings of an app prompt, and false when the set
// runs no app prompt.
func (s *Set) AppRun() (RunSettings, bool) {
	return s.appRun.clone(), s.appPrompts
}

type entryCommand struct {
	field string
	argv  []string
}

// commands lists the commands the entry runs in the project folder.
func (w WorkEntry) commands() []entryCommand {
	var out []entryCommand
	for _, c := range []struct {
		field string
		cfg   *BeforeConfig
	}{{"before", w.Before}, {"refresh", w.Refresh}, {"reset", w.Reset}} {
		if c.cfg != nil && len(c.cfg.Run) > 0 {
			out = append(out, entryCommand{c.field, c.cfg.Run})
		}
	}
	if w.Action == ActionCommand && len(w.Run) > 0 {
		out = append(out, entryCommand{"run", w.Run})
	}

	return out
}

// missingFile returns the command when it is a relative path, with no
// placeholder, to a file that dir lacks. A bare name, such as just, comes from
// PATH and is never checked.
func missingFile(dir string, argv []string) string {
	cmd := argv[0]
	if filepath.IsAbs(cmd) || !strings.Contains(cmd, "/") || strings.ContainsAny(cmd, "{~") {
		return ""
	}
	if _, err := os.Stat(filepath.Join(dir, cmd)); errors.Is(err, os.ErrNotExist) {
		return cmd
	}

	return ""
}

// advertised lists the kinds of the work maps that the bridge can run now, as
// WorkKindsOn names them.
func (s *Set) advertisedLocked() []string {
	out := []string{}
	kinds := s.allKinds()
	if len(s.entriesOf(TeardownKind)) == 0 && s.Isolation() == IsolationWorktree {
		kinds = append(kinds, TeardownKind)
		slices.Sort(kinds)
	}
	for _, kind := range kinds {
		entries := s.entriesOf(kind)
		if len(entries) == 0 {
			out = append(out, kind)

			continue
		}
		for _, entry := range entries {
			if s.agentsOff != "" && entry.Action != ActionCommand || s.offLocked(entry.runs(), "") {
				continue
			}
			name := kind
			if entry.Subject != api.SubjectCard {
				name = entry.Subject + ":" + kind
			}
			if !slices.Contains(out, name) {
				out = append(out, name)
			}
			if kind == SessionKind && !slices.Contains(out, api.SubjectInboxItem+":"+SessionKind) {
				out = append(out, api.SubjectInboxItem+":"+SessionKind)
			}
		}
	}

	return out
}

// rawEntryFor is the entry of a kind as written, for the project slug: the
// entry of the project, else the shared entry. It skips the covered entries
// of entryFor.
func (s *Set) rawEntryFor(slug, kind string) (WorkEntry, bool) {
	if w, ok := s.projectWork[slug][kind]; ok {
		return w, true
	}
	w, ok := s.work[kind]

	return w, ok
}

// attachProjectExperiments checks the experiments block of each project and
// gives each covered kind its experiment. A project entry of the kind takes
// the experiment. Otherwise the project gets a copy of the shared entry that
// holds it. It runs after the project entries and the shared experiments.
func (s *Set) attachProjectExperiments(f File, declared []string, root *yaml.Node) []error {
	var errs []error
	for _, slug := range slices.Sorted(maps.Keys(f.Projects)) {
		blocks := f.Projects[slug].Experiments
		if len(blocks) == 0 {
			continue
		}
		for _, name := range slices.Sorted(maps.Keys(blocks)) {
			if _, clash := f.Experiments[name]; clash {
				errs = append(errs, fmt.Errorf("project %q: experiment %q: the top-level block also names an experiment %q, and both would join one trial on the project page; rename one", slug, name, name))
			}
		}
		label := fmt.Sprintf("project %q: ", slug)
		covered, checkErrs := checkExperiments(func(kind string) (WorkEntry, bool) { return s.rawEntryFor(slug, kind) }, blocks, label)
		errs = append(errs, checkErrs...)
		for _, kind := range slices.Sorted(maps.Keys(covered)) {
			e := covered[kind]
			w, _ := s.rawEntryFor(slug, kind)
			errs = append(errs, s.checkVariantAccounts(kind, &w, &e, []any{"projects", slug, "experiments", e.Name}, label, declared, root)...)
			w.experiment = &e
			if _, own := s.projectWork[slug][kind]; own {
				s.projectWork[slug][kind] = w

				continue
			}
			if s.coveredWork[slug] == nil {
				s.coveredWork[slug] = map[string]WorkEntry{}
			}
			s.coveredWork[slug][kind] = w
		}
	}

	return errs
}

// CoveredWorkKinds lists the kinds for which the project holds a copy of the
// shared entry that carries a project experiment, in name order.
func (s *Set) CoveredWorkKinds(slug string) []string {
	return slices.Sorted(maps.Keys(s.coveredWork[slug]))
}

// CoveredWorkEntry is the copy of the shared entry that a project experiment
// covers.
func (s *Set) CoveredWorkEntry(slug, kind string) (WorkEntry, bool) {
	w, ok := s.coveredWork[slug][kind]

	return w, ok
}
