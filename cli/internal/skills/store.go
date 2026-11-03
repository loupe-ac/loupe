package skills

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"time"
)

// packageFile is the file of a package that names the lowest CLI it runs on and
// the packages it needs.
const packageFile = "loupe-package.json"

// RecordFile is the file in a run folder that names the version of each
// package the run started with.
const RecordFile = "packages.json"

// KeepAfterReplace is how long the store keeps a version after a newer one
// replaced it.
const KeepAfterReplace = 30 * 24 * time.Hour

// Pick is one package at one version, with the folder that holds it.
type Pick struct {
	Package string `json:"package"`
	Version string `json:"version"`
	Dir     string `json:"dir"`
}

// ErrMissing says a package is not in the store at any version the CLI can use.
var ErrMissing = errors.New("skill package is not in the store")

// StoreDir is the folder that holds every package version.
func StoreDir(configDir string) string { return filepath.Join(configDir, "skills") }

// Held lists the packages that the store holds at one version or more.
func Held(configDir string) []string {
	entries, _ := os.ReadDir(StoreDir(configDir))
	var held []string
	for _, e := range entries {
		if e.IsDir() && !strings.HasPrefix(e.Name(), ".") && e.Name() != "backup" && len(versions(configDir, e.Name())) > 0 {
			held = append(held, e.Name())
		}
	}

	return held
}

// versions lists the versions of a package in the store, the highest first.
func versions(configDir, pkg string) []string {
	entries, _ := os.ReadDir(filepath.Join(StoreDir(configDir), pkg))
	var out []string
	for _, e := range entries {
		if e.IsDir() && !strings.HasPrefix(e.Name(), ".") {
			if _, ok := parseVersion(e.Name()); ok {
				out = append(out, e.Name())
			}
		}
	}
	slices.SortFunc(out, func(a, b string) int { return -compareVersions(a, b) })

	return out
}

// Resolve picks the current version of each package: the highest one that
// meets minCli and whose requires the store can meet. A dev build, whose
// cliVersion is empty, meets every minCli. A package with no usable version
// gives ErrMissing.
func Resolve(configDir, cliVersion string, packages []string) ([]Pick, error) {
	picks := make([]Pick, 0, len(packages))
	for _, pkg := range packages {
		pick, ok := current(configDir, cliVersion, pkg)
		if !ok {
			return nil, fmt.Errorf("%w: %s", ErrMissing, pkg)
		}
		picks = append(picks, pick)
	}
	// A pick must meet the requires of its package against the other picks,
	// and a package the project does not use can still satisfy one.
	for _, pick := range picks {
		for dep, want := range readPackage(pick.Dir).Requires {
			have := ""
			for _, other := range picks {
				if other.Package == dep {
					have = other.Version
				}
			}
			if have == "" {
				if p, ok := current(configDir, cliVersion, dep); ok {
					have = p.Version
				}
			}
			if have == "" || !satisfies(have, want) {
				return nil, fmt.Errorf("%w: %s needs %s %s", ErrMissing, pick.Package, dep, want)
			}
		}
	}

	return picks, nil
}

// Exact returns the folders of the recorded versions, and false when any of
// them is gone.
func Exact(configDir string, recorded map[string]string) ([]Pick, bool) {
	picks := make([]Pick, 0, len(recorded))
	for _, pkg := range slices.Sorted(mapKeys(recorded)) {
		dir := filepath.Join(StoreDir(configDir), pkg, recorded[pkg])
		if info, err := os.Stat(dir); err != nil || !info.IsDir() {
			return nil, false
		}
		picks = append(picks, Pick{Package: pkg, Version: recorded[pkg], Dir: dir})
	}

	return picks, len(picks) > 0
}

func mapKeys(m map[string]string) func(yield func(string) bool) {
	return func(yield func(string) bool) {
		for k := range m {
			if !yield(k) {
				return
			}
		}
	}
}

func current(configDir, cliVersion, pkg string) (Pick, bool) {
	for _, v := range versions(configDir, pkg) {
		dir := filepath.Join(StoreDir(configDir), pkg, v)
		meta := readPackage(dir)
		if meta.MinCli != "" && cliVersion != "" && compareVersions(cliVersion, meta.MinCli) < 0 {
			continue
		}

		return Pick{Package: pkg, Version: v, Dir: dir}, true
	}

	return Pick{}, false
}

type packageMeta struct {
	MinCli   string            `json:"minCli"`
	Requires map[string]string `json:"requires"`
}

func readPackage(dir string) packageMeta {
	var meta packageMeta
	if data, err := os.ReadFile(filepath.Join(dir, packageFile)); err == nil {
		_ = json.Unmarshal(data, &meta)
	}

	return meta
}

// WriteRecord writes the versions of a run to its folder.
func WriteRecord(runDir string, picks []Pick) error {
	recorded := make(map[string]string, len(picks))
	for _, p := range picks {
		recorded[p.Package] = p.Version
	}
	data, err := json.Marshal(recorded)
	if err != nil {
		return err
	}

	return os.WriteFile(filepath.Join(runDir, RecordFile), data, 0o600)
}

// ReadRecord reads the versions a run started with. It gives nil for a run
// with no record, such as one that started before the record existed.
func ReadRecord(runDir string) map[string]string {
	data, err := os.ReadFile(filepath.Join(runDir, RecordFile))
	if err != nil {
		return nil
	}
	var recorded map[string]string
	if json.Unmarshal(data, &recorded) != nil {
		return nil
	}

	return recorded
}

// Prune deletes each version that a newer one replaced at least KeepAfterReplace
// ago, unless inUse says a live run holds it. The time of the replacement is
// the time the next higher version reached the store. It returns what it
// deleted, as package/version.
func Prune(configDir string, now time.Time, inUse func(pkg, version string) bool) []string {
	var removed []string
	for _, pkg := range Held(configDir) {
		list := versions(configDir, pkg)
		for i := 1; i < len(list); i++ {
			info, err := os.Stat(filepath.Join(StoreDir(configDir), pkg, list[i-1]))
			if err != nil || now.Sub(info.ModTime()) < KeepAfterReplace {
				continue
			}
			if inUse != nil && inUse(pkg, list[i]) {
				continue
			}
			if os.RemoveAll(filepath.Join(StoreDir(configDir), pkg, list[i])) == nil {
				removed = append(removed, pkg+"/"+list[i])
			}
		}
	}

	return removed
}

// parseVersion reads "1.2.3", with an optional leading v and a pre-release
// suffix that it ignores.
func parseVersion(s string) ([3]int, bool) {
	s = strings.TrimPrefix(s, "v")
	s, _, _ = strings.Cut(s, "-")
	s, _, _ = strings.Cut(s, "+")
	parts := strings.Split(s, ".")
	if len(parts) != 3 {
		return [3]int{}, false
	}
	var v [3]int
	for i, p := range parts {
		n, err := strconv.Atoi(p)
		if err != nil || n < 0 {
			return [3]int{}, false
		}
		v[i] = n
	}

	return v, true
}

// compareVersions orders two versions. A value that does not parse sorts lowest.
func compareVersions(a, b string) int {
	va, oka := parseVersion(a)
	vb, okb := parseVersion(b)
	switch {
	case !oka && !okb:
		return 0
	case !oka:
		return -1
	case !okb:
		return 1
	}
	for i := range va {
		if va[i] != vb[i] {
			if va[i] < vb[i] {
				return -1
			}

			return 1
		}
	}

	return 0
}

// satisfies reports whether have meets every comparison of want, such as
// ">=0.55.0" or ">=1.0.0 <2.0.0". A comparison it cannot read fails.
func satisfies(have, want string) bool {
	for _, term := range strings.FieldsFunc(want, func(r rune) bool { return r == ',' || r == ' ' }) {
		op := ""
		for _, candidate := range []string{">=", "<=", ">", "<", "="} {
			if strings.HasPrefix(term, candidate) {
				op = candidate

				break
			}
		}
		rest := strings.TrimPrefix(term, op)
		if _, ok := parseVersion(rest); !ok {
			return false
		}
		c := compareVersions(have, rest)
		var ok bool
		switch op {
		case ">=":
			ok = c >= 0
		case "<=":
			ok = c <= 0
		case ">":
			ok = c > 0
		case "<":
			ok = c < 0
		default:
			ok = c == 0
		}
		if !ok {
			return false
		}
	}

	return true
}
