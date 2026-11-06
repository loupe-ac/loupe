package rules

import (
	"bytes"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"reflect"

	"github.com/loupe-ac/loupe/cli/internal/update"
	"go.yaml.in/yaml/v3"
)

// The old and the new name of the kind that runs after the work of a rule
// failed and its retries ran out.
const (
	RecoverKind      = "recover"
	LegacyRepairKind = "repair"
)

// refuseRepairKey names each work map that still holds the old kind. The
// migration renames it at the start of the bridge, and a reload does not run
// the migration, so a hand-edited old key fails loudly.
func refuseRepairKey(f File) []error {
	var errs []error
	if _, ok := f.Work[LegacyRepairKind]; ok {
		errs = append(errs, fmt.Errorf("work %q is renamed to %q; rename the key, and restart the bridge to rename it for you", LegacyRepairKind, RecoverKind))
	}
	for slug, p := range f.Projects {
		if _, ok := p.Work[LegacyRepairKind]; ok {
			errs = append(errs, fmt.Errorf("project %q work %q is renamed to %q; rename the key, and restart the bridge to rename it for you", slug, LegacyRepairKind, RecoverKind))
		}
	}

	return errs
}

// MigrateRepairKey renames the work key repair to recover in the shared map
// and in the map of each project, and the kind in the covers of an experiment.
// Only those tokens change, and the write happens only when the file then
// decodes as expected. A map that already holds recover is refused, because
// the rename would drop one of the two entries.
func MigrateRepairKey(path string) (changed bool, err error) {
	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return false, nil
	}
	if err != nil {
		return false, fmt.Errorf("read rule file: %w", err)
	}
	old, err := decodeMap(data)
	if err != nil {
		return false, err
	}
	root := contentRoot(data)
	if old == nil || root == nil || root.Kind != yaml.MappingNode {
		return false, nil
	}
	spots, err := repairSpots(root)
	if err != nil || len(spots) == 0 {
		return false, err
	}
	var edits []lineEdit
	for _, n := range spots {
		edit, ok := renameScalar(data, n)
		if !ok {
			return false, fmt.Errorf("%w: the kind %s on line %d is not a plain or a quoted word", ErrMigrationRefused, LegacyRepairKind, n.Line)
		}
		edits = append(edits, edit)
	}
	next := applyEdits(data, edits)
	want := renamedRepair(old)
	if got, err := decodeMap(next); err != nil || !reflect.DeepEqual(got, want) {
		return false, fmt.Errorf("%w: the edited file would not hold the same rules", ErrMigrationRefused)
	}
	real, err := filepath.EvalSymlinks(path)
	if err != nil {
		return false, err
	}
	info, err := os.Stat(real)
	if err != nil {
		return false, err
	}
	if err := update.WriteFile(real, next, info.Mode().Perm()); err != nil {
		return false, err
	}

	return true, nil
}

// renameScalar is the edit that renames the repair scalar n in place. A
// quoted scalar keeps its quotes. A scalar with an escape is refused.
func renameScalar(data []byte, n *yaml.Node) (lineEdit, bool) {
	start, _, ok := tokenAt(data, n.Line, n.Column)
	if !ok {
		return lineEdit{}, false
	}
	var quote string
	switch n.Style {
	case 0:
	case yaml.DoubleQuotedStyle:
		quote = `"`
	case yaml.SingleQuotedStyle:
		quote = "'"
	default:
		return lineEdit{}, false
	}
	if !bytes.HasPrefix(data[start:], []byte(quote+LegacyRepairKind+quote)) {
		return lineEdit{}, false
	}
	start += len(quote)

	return lineEdit{start, start + len(LegacyRepairKind), RecoverKind}, true
}

// repairSpots lists the key nodes, the covers items and the variant kinds
// overrides that name repair.
func repairSpots(root *yaml.Node) ([]*yaml.Node, error) {
	var spots []*yaml.Node
	rename := func(where string, m *yaml.Node) error {
		k, _ := pair(m, LegacyRepairKind)
		if k == nil {
			return nil
		}
		if r, _ := pair(m, RecoverKind); r != nil {
			return fmt.Errorf("%w: %s holds both %s and %s", ErrMigrationRefused, where, LegacyRepairKind, RecoverKind)
		}
		spots = append(spots, k)

		return nil
	}
	if _, work := pair(root, "work"); work != nil && work.Kind == yaml.MappingNode {
		if err := rename("work", work); err != nil {
			return nil, err
		}
	}
	if _, projects := pair(root, "projects"); projects != nil && projects.Kind == yaml.MappingNode {
		for i := 0; i+1 < len(projects.Content); i += 2 {
			if _, work := pair(projects.Content[i+1], "work"); work != nil && work.Kind == yaml.MappingNode {
				if err := rename("project "+projects.Content[i].Value+" work", work); err != nil {
					return nil, err
				}
			}
		}
	}
	if _, experiments := pair(root, "experiments"); experiments != nil && experiments.Kind == yaml.MappingNode {
		for i := 0; i+1 < len(experiments.Content); i += 2 {
			name := experiments.Content[i].Value
			if _, covers := pair(experiments.Content[i+1], "covers"); covers != nil && covers.Kind == yaml.SequenceNode {
				var found []*yaml.Node
				hasRecover := false
				for _, item := range covers.Content {
					if item.Kind != yaml.ScalarNode {
						continue
					}
					switch item.Value {
					case LegacyRepairKind:
						found = append(found, item)
					case RecoverKind:
						hasRecover = true
					}
				}
				if len(found) > 0 && hasRecover {
					return nil, fmt.Errorf("%w: experiment %s covers both %s and %s", ErrMigrationRefused, name, LegacyRepairKind, RecoverKind)
				}
				spots = append(spots, found...)
			}
			if _, variants := pair(experiments.Content[i+1], "variants"); variants != nil && variants.Kind == yaml.SequenceNode {
				for _, v := range variants.Content {
					if _, kinds := pair(v, "kinds"); kinds != nil && kinds.Kind == yaml.MappingNode {
						if err := rename("experiment "+name+" variant kinds", kinds); err != nil {
							return nil, err
						}
					}
				}
			}
		}
	}

	return spots, nil
}

// renamedRepair is the decoded content the migrated file must hold.
func renamedRepair(old map[string]any) map[string]any {
	want := map[string]any{}
	for k, v := range old {
		want[k] = v
	}
	renameKey := func(m any) any {
		in, ok := m.(map[string]any)
		if !ok {
			return m
		}
		out := map[string]any{}
		for k, v := range in {
			if k == LegacyRepairKind {
				k = RecoverKind
			}
			out[k] = v
		}

		return out
	}
	if v, ok := want["work"]; ok {
		want["work"] = renameKey(v)
	}
	if projects, ok := want["projects"].(map[string]any); ok {
		next := map[string]any{}
		for slug, p := range projects {
			if pm, ok := p.(map[string]any); ok {
				c := map[string]any{}
				for k, v := range pm {
					c[k] = v
				}
				if v, ok := c["work"]; ok {
					c["work"] = renameKey(v)
				}
				next[slug] = c
			} else {
				next[slug] = p
			}
		}
		want["projects"] = next
	}
	if experiments, ok := want["experiments"].(map[string]any); ok {
		next := map[string]any{}
		for name, e := range experiments {
			em, ok := e.(map[string]any)
			if !ok {
				next[name] = e
				continue
			}
			c := map[string]any{}
			for k, v := range em {
				c[k] = v
			}
			if covers, ok := c["covers"].([]any); ok {
				items := make([]any, len(covers))
				for i, item := range covers {
					if item == LegacyRepairKind {
						item = RecoverKind
					}
					items[i] = item
				}
				c["covers"] = items
			}
			if variants, ok := c["variants"].([]any); ok {
				items := make([]any, len(variants))
				for i, v := range variants {
					if vm, ok := v.(map[string]any); ok {
						vc := map[string]any{}
						for k, x := range vm {
							vc[k] = x
						}
						if kinds, ok := vc["kinds"]; ok {
							vc["kinds"] = renameKey(kinds)
						}
						v = vc
					}
					items[i] = v
				}
				c["variants"] = items
			}
			next[name] = c
		}
		want["experiments"] = next
	}

	return want
}
