package rules

import (
	"path/filepath"
	"slices"
	"strings"
)

// Gaps names what a rule file lacks for dir to run work: a permission default,
// a source of prompts, and a project that maps dir. keys are the handles of the
// project linked to dir. Only an entry under the first key counts, because the
// bridge takes only the slug. An entry under another key, such as the id, is a
// gap that names it. With no keys, any entry counts. The file may be valid and
// still run nothing, so a gap is a note and never an error.
func Gaps(data []byte, dir string, keys ...string) ([]string, error) {
	m, err := decodeMap(data)
	if err != nil {
		return nil, err
	}
	var gaps []string
	if !hasPermissionDefault(m) {
		gaps = append(gaps, "it sets no permission default: add defaults.permissions, or a permissionMode on an account")
	}
	if !hasPrompts(m) {
		gaps = append(gaps, "it has no prompts: set appPrompts to true, or add a work entry")
	}
	switch {
	case mapsDir(m, dir, keys[:min(len(keys), 1)]) != "":
	case len(keys) > 1 && mapsDir(m, dir, keys[1:]) != "":
		gaps = append(gaps, "project "+keys[0]+" is mapped under projects."+mapsDir(m, dir, keys[1:])+", and the bridge takes only projects."+keys[0])
	case len(keys) > 0:
		gaps = append(gaps, "project "+keys[0]+" does not map "+dir+": add it under projects")
	default:
		gaps = append(gaps, "no project maps "+dir+": add it under projects")
	}

	return gaps, nil
}

func hasPermissionDefault(m map[string]any) bool {
	defaults, _ := m["defaults"].(map[string]any)
	for _, key := range []string{"permissions", "permissionMode"} {
		if text(defaults[key]) != "" {
			return true
		}
	}
	accounts, _ := m["accounts"].(map[string]any)
	for _, account := range accounts {
		fields, _ := account.(map[string]any)
		if text(fields["permissionMode"]) != "" {
			return true
		}
	}

	return false
}

func hasPrompts(m map[string]any) bool {
	if on, _ := m["appPrompts"].(bool); on {
		return true
	}
	work, _ := m["work"].(map[string]any)

	return len(work) > 0
}

// mapsDir returns the key of an entry under one of keys that maps dir, or ""
// when none does. With no keys, any entry counts.
func mapsDir(m map[string]any, dir string, keys []string) string {
	want := canonical(dir)
	projects, _ := m["projects"].(map[string]any)
	for key, project := range projects {
		if len(keys) > 0 && !slices.ContainsFunc(keys, func(k string) bool { return k != "" && strings.EqualFold(k, key) }) {
			continue
		}
		fields, _ := project.(map[string]any)
		dir := text(fields["dir"])
		if dir == "" {
			continue
		}
		if expanded, err := expandHome(dir); err == nil && canonical(expanded) == want {
			return key
		}
	}

	return ""
}

func text(v any) string {
	s, _ := v.(string)

	return strings.TrimSpace(s)
}

// canonical resolves symlinks, so two spellings of one directory compare
// equal. A path that does not resolve compares by its cleaned form.
func canonical(dir string) string {
	if real, err := filepath.EvalSymlinks(dir); err == nil {
		return real
	}

	return filepath.Clean(dir)
}
