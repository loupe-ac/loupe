package rules

import (
	"path/filepath"
	"strings"
)

// Gaps names what a rule file lacks for dir to run work: a permission default,
// a source of prompts, and a project that maps dir. The file may be valid and
// still run nothing, so a gap is a note and never an error. An empty result
// means the file lacks nothing.
func Gaps(data []byte, dir string) ([]string, error) {
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
	if !mapsDir(m, dir) {
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

func mapsDir(m map[string]any, dir string) bool {
	want := canonical(dir)
	projects, _ := m["projects"].(map[string]any)
	for _, project := range projects {
		fields, _ := project.(map[string]any)
		dir := text(fields["dir"])
		if dir == "" {
			continue
		}
		if expanded, err := expandHome(dir); err == nil && canonical(expanded) == want {
			return true
		}
	}

	return false
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
