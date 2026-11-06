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
	kind = entryKind(kind)
	if w, ok := s.projectWork[slug][kind]; ok {
		return w, true
	}
	w, ok := s.work[kind]

	return w, ok
}

// entryKind is the kind of the work entry that runs a request kind. The old
// request kind repair runs the renamed entry recover, until the app sends only
// the new kind.
func entryKind(kind string) string {
	if kind == LegacyRepairKind {
		return RecoverKind
	}

	return kind
}

// allEntries lists the shared entries, then the entries of each project, in
// kind order. The bridge-wide lists read it.
func (s *Set) allEntries() []WorkEntry {
	var out []WorkEntry
	for _, kind := range slices.Sorted(maps.Keys(s.work)) {
		out = append(out, s.work[kind])
	}
	for _, slug := range slices.Sorted(maps.Keys(s.projectWork)) {
		for _, kind := range slices.Sorted(maps.Keys(s.projectWork[slug])) {
			out = append(out, s.projectWork[slug][kind])
		}
	}

	return out
}

// entriesOf lists every entry of a kind: the shared one, then one for each
// project that lists the kind.
func (s *Set) entriesOf(kind string) []WorkEntry {
	var out []WorkEntry
	if w, ok := s.work[kind]; ok {
		out = append(out, w)
	}
	for _, slug := range slices.Sorted(maps.Keys(s.projectWork)) {
		if w, ok := s.projectWork[slug][kind]; ok {
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
//   - A project entry of a kind that an experiment covers, which runs
//     without the variants.
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
			if _, own := s.projectWork[slug][kind]; own && s.work[kind].experiment != nil {
				out = append(out, fmt.Sprintf("project %s: work %s covers an experiment on the shared entry only, so the entry of this project runs without the variants", slug, kind))
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
			if kind == RecoverKind {
				// The app sends the old kind until it deploys with the new one.
				if legacy := strings.TrimSuffix(name, RecoverKind) + LegacyRepairKind; !slices.Contains(out, legacy) {
					out = append(out, legacy)
				}
			}
			if kind == SessionKind && !slices.Contains(out, api.SubjectInboxItem+":"+SessionKind) {
				out = append(out, api.SubjectInboxItem+":"+SessionKind)
			}
		}
	}

	return out
}
