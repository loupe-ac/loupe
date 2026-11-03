package skills

import (
	"encoding/json"
	"errors"
	"fmt"
	"maps"
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

// recordsDir is the folder in the store that holds one file per session, which
// names the version of each package the session started with. A record stays
// after its run folder goes, because a resume reads it later.
const recordsDir = ".records"

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
// meets minCli and whose requires the store can meet, with a lower version when
// a higher one does not fit. A package earlier in the list gets the first choice
// of version. A dev build, whose cliVersion is empty, meets every minCli. A
// package with no usable version gives ErrMissing.
func Resolve(configDir, cliVersion string, packages []string) ([]Pick, error) {
	chosen, err := solve(configDir, cliVersion, packages)
	if err != nil {
		return nil, err
	}
	picks := make([]Pick, 0, len(packages))
	for _, pkg := range packages {
		picks = append(picks, chosen[pkg].Pick)
	}

	return picks, nil
}

// Current names, as package/version, each version that a pick for one of the
// lists uses, also a dependency outside the list. A list the store cannot serve
// adds nothing.
func Current(configDir, cliVersion string, lists [][]string) map[string]bool {
	current := map[string]bool{}
	for _, packages := range lists {
		chosen, err := solve(configDir, cliVersion, packages)
		if err != nil {
			continue
		}
		for _, c := range chosen {
			current[c.Package+"/"+c.Version] = true
		}
	}

	return current
}

// candidate is a version a CLI can run, with what it needs.
type candidate struct {
	Pick
	meta packageMeta
}

// solver searches the versions for one set that meets every requires. failure
// keeps the first reason a version did not fit, which belongs to the highest
// versions the search tried.
type solver struct {
	configDir, cliVersion string
	failure               error
}

func solve(configDir, cliVersion string, packages []string) (map[string]candidate, error) {
	s := solver{configDir: configDir, cliVersion: cliVersion}
	chosen := map[string]candidate{}
	if !s.search(packages, chosen) {
		return nil, s.failure
	}

	return chosen, nil
}

// search fills chosen for every package in todo and for what those need. It
// returns false, with chosen as it found it, when no set of versions fits.
func (s *solver) search(todo []string, chosen map[string]candidate) bool {
	if len(todo) == 0 {
		return true
	}
	pkg, rest := todo[0], todo[1:]
	if _, done := chosen[pkg]; done {
		return s.search(rest, chosen)
	}
	list := s.candidates(pkg)
	if len(list) == 0 {
		s.fail(fmt.Errorf("%w: %s", ErrMissing, pkg))

		return false
	}
	for _, c := range list {
		if !s.fits(c, chosen) {
			continue
		}
		chosen[pkg] = c
		if s.search(append(slices.Clone(rest), slices.Sorted(maps.Keys(c.meta.Requires))...), chosen) {
			return true
		}
		delete(chosen, pkg)
	}

	return false
}

// fits reports whether c and the versions chosen so far meet the requires of
// each other.
func (s *solver) fits(c candidate, chosen map[string]candidate) bool {
	for dep, want := range c.meta.Requires {
		if other, ok := chosen[dep]; ok && !satisfies(other.Version, want) {
			s.fail(fmt.Errorf("%w: %s needs %s %s", ErrMissing, c.Package, dep, want))

			return false
		}
	}
	for _, other := range chosen {
		if want, ok := other.meta.Requires[c.Package]; ok && !satisfies(c.Version, want) {
			s.fail(fmt.Errorf("%w: %s needs %s %s", ErrMissing, other.Package, c.Package, want))

			return false
		}
	}

	return true
}

func (s *solver) fail(err error) {
	if s.failure == nil {
		s.failure = err
	}
}

// candidates lists the versions of a package that meet minCli, the highest
// first.
func (s *solver) candidates(pkg string) []candidate {
	var list []candidate
	for _, v := range versions(s.configDir, pkg) {
		dir := filepath.Join(StoreDir(s.configDir), pkg, v)
		meta := readPackage(dir)
		if meta.MinCli != "" && s.cliVersion != "" && compareVersions(s.cliVersion, meta.MinCli) < 0 {
			continue
		}
		list = append(list, candidate{Pick{Package: pkg, Version: v, Dir: dir}, meta})
	}

	return list
}

// Exact returns the folders of the recorded versions, and false when any of
// them is gone.
func Exact(configDir string, recorded map[string]string) ([]Pick, bool) {
	picks := make([]Pick, 0, len(recorded))
	for _, pkg := range slices.Sorted(maps.Keys(recorded)) {
		dir := filepath.Join(StoreDir(configDir), pkg, recorded[pkg])
		if info, err := os.Stat(dir); err != nil || !info.IsDir() {
			return nil, false
		}
		picks = append(picks, Pick{Package: pkg, Version: recorded[pkg], Dir: dir})
	}

	return picks, len(picks) > 0
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

// recordPath is the file of a session's record. A session id comes from the
// app, so one that could leave the folder has no record.
func recordPath(configDir, session string) (string, bool) {
	if session == "" || strings.HasPrefix(session, ".") || filepath.Base(session) != session {
		return "", false
	}

	return filepath.Join(StoreDir(configDir), recordsDir, session+".json"), true
}

// WriteRecord writes the versions a session starts with.
func WriteRecord(configDir, session string, picks []Pick) error {
	path, ok := recordPath(configDir, session)
	if !ok {
		return fmt.Errorf("session id %q cannot name a record", session)
	}
	recorded := make(map[string]string, len(picks))
	for _, p := range picks {
		recorded[p.Package] = p.Version
	}
	data, err := json.Marshal(recorded)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}

	return os.WriteFile(path, data, 0o600)
}

// ReadRecord reads the versions a session started with. It gives nil for a
// session with no record, such as one that started before the record existed.
func ReadRecord(configDir, session string) map[string]string {
	path, ok := recordPath(configDir, session)
	if !ok {
		return nil
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil
	}
	var recorded map[string]string
	if json.Unmarshal(data, &recorded) != nil {
		return nil
	}

	return recorded
}

// Records gives the time each session's record was last written.
func Records(configDir string) map[string]time.Time {
	entries, _ := os.ReadDir(filepath.Join(StoreDir(configDir), recordsDir))
	records := make(map[string]time.Time, len(entries))
	for _, e := range entries {
		session, isRecord := strings.CutSuffix(e.Name(), ".json")
		if info, err := e.Info(); isRecord && err == nil {
			records[session] = info.ModTime()
		}
	}

	return records
}

// pruneRecords deletes each record older than KeepAfterReplace, unless live
// says its session still runs. A later resume of a deleted record's session
// takes the current versions.
func pruneRecords(configDir string, now time.Time, live func(session string) bool) {
	for session, at := range Records(configDir) {
		if now.Sub(at) < KeepAfterReplace || (live != nil && live(session)) {
			continue
		}
		_ = os.Remove(filepath.Join(StoreDir(configDir), recordsDir, session+".json"))
	}
}

// Prune deletes each version that a newer one replaced at least KeepAfterReplace
// ago, unless inUse says something still uses it, and each old record of a
// session that live does not name. The time of the replacement is the time the
// next higher version reached the store. It returns the versions it deleted, as
// package/version.
func Prune(configDir string, now time.Time, live func(session string) bool, inUse func(pkg, version string) bool) []string {
	pruneRecords(configDir, now, live)
	pruneHomes(configDir, now, live)
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
