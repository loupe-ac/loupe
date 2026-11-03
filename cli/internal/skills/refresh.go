package skills

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"maps"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/update"
)

// The states of a package, as the heartbeat reports them.
const (
	StateCurrent = "current"
	StateFailed  = "failed"
)

// tagPrefix starts the release tag of a package, such as skills/loupe/v1.2.0.
const tagPrefix = "skills/"

// checksumsName is the checksums asset of a release.
const checksumsName = "checksums.txt"

// failuresDir is the folder in the store that holds one file for each package
// whose last update failed.
const failuresDir = ".failures"

// packageName is what the app accepts as a package name. The name becomes a
// folder in the store, so nothing else gets through.
var packageName = regexp.MustCompile(`^[a-z][a-z0-9]*(-[a-z0-9]+)*$`)

// ValidName reports whether name can be a package name: the form the app
// accepts, short enough to be a folder name.
func ValidName(name string) bool { return len(name) <= 64 && packageName.MatchString(name) }

// Result is what a refresh found for one package. Version is the highest
// version the store holds that the CLI can use and whose requires the store can
// meet, and empty when there is none.
// Installed is the version this refresh wrote, if any.
type Result struct {
	Package   string `json:"package"`
	Version   string `json:"version"`
	State     string `json:"state"`
	Reason    string `json:"reason,omitempty"`
	Installed string `json:"installed,omitempty"`
}

// Status reads a package from the store alone. A package with no usable version
// is failed.
func Status(configDir, cliVersion, pkg string) Result {
	chosen, err := solve(configDir, cliVersion, []string{pkg})
	if err != nil {
		return Result{Package: pkg, State: StateFailed, Reason: fmt.Sprintf("the store holds no version of %s that this CLI can use: %v", pkg, err)}
	}

	return Result{Package: pkg, Version: chosen[pkg].Version, State: StateCurrent}
}

// Report reads a package as a bridge and `loupe status` show it: the store, and
// the failure of the last update. Any process can run that update, so the store
// keeps the failure. It ends when the store holds another version.
func Report(configDir, cliVersion, pkg string) Result {
	r := Status(configDir, cliVersion, pkg)
	if failure, ok := readFailure(configDir, pkg); ok && failure.Version == r.Version {
		r.State, r.Reason = StateFailed, failure.Reason
	}

	return r
}

// failurePath is the file that holds the last failed update of a package, and
// false for a name that cannot be a file of the store.
func failurePath(configDir, pkg string) (string, bool) {
	if !ValidName(pkg) {
		return "", false
	}

	return filepath.Join(StoreDir(configDir), failuresDir, pkg+".json"), true
}

func readFailure(configDir, pkg string) (Result, bool) {
	path, ok := failurePath(configDir, pkg)
	if !ok {
		return Result{}, false
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return Result{}, false
	}
	var failure Result
	if json.Unmarshal(data, &failure) != nil {
		return Result{}, false
	}

	return failure, true
}

// RecordResults keeps each failed update for Report, and ends the failure of each
// package that did not fail. A store that takes no file refuses the record of a
// session too, which stops the run with the reason, so a failed write here is
// not reported again.
func RecordResults(configDir string, results []Result) {
	for _, r := range results {
		path, ok := failurePath(configDir, r.Package)
		if !ok {
			continue
		}
		if r.State != StateFailed {
			_ = os.Remove(path)

			continue
		}
		_ = writeFailure(path, r)
	}
}

// writeFailure renames the file into place, so a reader in another process never
// sees half of it.
func writeFailure(path string, failure Result) error {
	data, err := json.Marshal(failure)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".write-")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()

		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}

	return os.Rename(tmp.Name(), path)
}

// release is one published version of a package.
type release struct {
	version            string
	archive, checksums update.Asset
	archiveName        string
}

// Refresh installs, for each package, the newest release the store lacks, from
// the releases list. It never removes a version, and it changes nothing when a
// step fails: a version is unpacked in a temporary folder beside its place and
// renamed into it at the end. A version whose minCli the CLI does not meet, and a
// version whose whole set of requires the store cannot meet, stay out and give a
// reason. A dev build, whose cliVersion is empty, meets every minCli. Report reads
// the failures after this, unless ctx ended, which says nothing about a package.
//
// blocked remembers the versions that the CLI cannot use, so no later call
// downloads them again. The caller keeps it for the life of the process.
func Refresh(ctx context.Context, hc *http.Client, configDir, cliVersion string, releases []update.Release, packages []string, blocked map[string]string) []Result {
	names := slices.Compact(slices.Sorted(slices.Values(packages)))
	results := make([]Result, len(names))
	installed := map[string]string{}
	// A package that needs another can only fit once that one is in the store, so
	// a pass that installs something runs once more.
	for pass := 0; pass <= len(names); pass++ {
		progress := false
		for i, pkg := range names {
			r := refreshPackage(ctx, hc, configDir, cliVersion, releases, pkg, blocked)
			if r.Installed != "" {
				progress = true
				installed[pkg] = r.Installed
			}
			if v, ok := installed[pkg]; ok {
				r.Installed = v
			}
			results[i] = r
		}
		if !progress {
			break
		}
	}
	if ctx.Err() == nil {
		RecordResults(configDir, results)
	}

	return results
}

func refreshPackage(ctx context.Context, hc *http.Client, configDir, cliVersion string, releases []update.Release, pkg string, blocked map[string]string) Result {
	if !ValidName(pkg) {
		return Result{Package: pkg, State: StateFailed, Reason: fmt.Sprintf("%q is not a valid package name", pkg)}
	}
	s := solver{configDir: configDir, cliVersion: cliVersion}
	have := Status(configDir, cliVersion, pkg).Version
	published := publishedVersions(releases, pkg)
	if len(published) == 0 && len(versions(configDir, pkg)) == 0 {
		return Result{Package: pkg, State: StateFailed, Reason: "no release of " + pkg + " exists"}
	}

	var reason, installed string
	for _, rel := range published {
		if have != "" && compareVersions(rel.version, have) <= 0 {
			break
		}
		if _, err := os.Stat(filepath.Join(StoreDir(configDir), pkg, rel.version)); err == nil {
			continue
		}
		key := pkg + "/" + rel.version
		if why, ok := blocked[key]; ok {
			reason = cmpReason(reason, why)

			continue
		}
		err := installRelease(ctx, hc, &s, pkg, rel)
		var held heldBack
		switch {
		case err == nil:
			installed = rel.version
		case errors.As(err, &held):
			if held.permanent {
				blocked[key] = held.Error()
			}
			reason = cmpReason(reason, held.Error())

			continue
		default:
			return Result{Package: pkg, Version: have, State: StateFailed, Reason: fmt.Sprintf("install %s %s: %v", pkg, rel.version, err)}
		}

		break
	}
	// The store has the last word on what a worker can start with. A stored
	// version above that one cannot start, and the store can hold one from
	// another CLI, or after its dependency went.
	r := Status(configDir, cliVersion, pkg)
	r.Installed = installed
	if reason == "" && r.State == StateCurrent {
		reason = s.unusableAbove(pkg, r.Version)
	}
	if reason != "" {
		r.State, r.Reason = StateFailed, reason
	}

	return r
}

// unusableAbove says why the highest stored version above have stays out, and
// gives "" when the store holds none.
func (s solver) unusableAbove(pkg, have string) string {
	for _, v := range versions(s.configDir, pkg) {
		if compareVersions(v, have) <= 0 {
			break
		}
		if err := s.unfit(pkg, v, filepath.Join(StoreDir(s.configDir), pkg, v)); err != nil {
			return err.Error()
		}
	}

	return ""
}

// unfit returns a heldBack error when the version in dir does not fit this CLI,
// or when the store cannot meet its whole set of requires, also what those
// packages need in turn. dir need not be in the store yet.
func (s solver) unfit(pkg, version, dir string) error {
	meta := readPackage(dir)
	if meta.MinCli != "" && s.cliVersion != "" && compareVersions(s.cliVersion, meta.MinCli) < 0 {
		return heldBack{reason: fmt.Sprintf("%s %s needs CLI %s or later, and this CLI is %s", pkg, version, meta.MinCli, s.cliVersion), permanent: true}
	}
	search := solver{configDir: s.configDir, cliVersion: s.cliVersion}
	chosen := map[string]candidate{pkg: {Pick{Package: pkg, Version: version, Dir: dir}, meta}}
	if !search.search(slices.Sorted(maps.Keys(meta.Requires)), chosen) {
		return heldBack{reason: fmt.Sprintf("%s %s stays out: %v", pkg, version, search.failure)}
	}

	return nil
}

// cmpReason keeps the first reason, which belongs to the newest version.
func cmpReason(have, next string) string {
	if have != "" {
		return have
	}

	return next
}

// heldBack says a version is good but does not fit this CLI or this store.
// permanent says it will not fit later, for the same CLI.
type heldBack struct {
	reason    string
	permanent bool
}

func (h heldBack) Error() string { return h.reason }

// publishedVersions lists the releases of a package that a CLI may use, the
// highest first: not a draft, not a prerelease, with the archive and the
// checksums file.
func publishedVersions(releases []update.Release, pkg string) []release {
	var out []release
	for _, r := range releases {
		if r.Draft || r.Prerelease {
			continue
		}
		rest, ok := strings.CutPrefix(r.TagName, tagPrefix)
		if !ok {
			continue
		}
		name, tagVersion, ok := strings.Cut(rest, "/v")
		if !ok || name != pkg || !exactVersion(tagVersion) {
			continue
		}
		archiveName := pkg + "_" + tagVersion + ".tar.gz"
		var rel release
		for _, a := range r.Assets {
			switch a.Name {
			case archiveName:
				rel.archive = a
			case checksumsName:
				rel.checksums = a
			}
		}
		if rel.archive.URL == "" || rel.checksums.URL == "" {
			continue
		}
		rel.version, rel.archiveName = tagVersion, archiveName
		out = append(out, rel)
	}
	slices.SortFunc(out, func(a, b release) int { return -compareVersions(a.version, b.version) })

	return slices.CompactFunc(out, func(a, b release) bool { return a.version == b.version })
}

// exactVersion reports whether s is X.Y.Z with no prefix, suffix or leading zero.
func exactVersion(s string) bool {
	v, ok := parseVersion(s)

	return ok && s == fmt.Sprintf("%d.%d.%d", v[0], v[1], v[2])
}

// installRelease downloads, verifies and unpacks one release, and moves it into the
// store. It returns a heldBack error for a version that does not fit.
func installRelease(ctx context.Context, hc *http.Client, s *solver, pkg string, rel release) error {
	archive, err := update.Download(ctx, hc, rel.archive.URL)
	if err != nil {
		return err
	}
	checksums, err := update.Download(ctx, hc, rel.checksums.URL)
	if err != nil {
		return err
	}
	if err := update.Verify(archive, checksums, rel.archiveName); err != nil {
		return err
	}

	parent := filepath.Join(StoreDir(s.configDir), pkg)
	if err := os.MkdirAll(parent, 0o755); err != nil {
		return err
	}
	tmp, err := os.MkdirTemp(parent, ".unpack-")
	if err != nil {
		return err
	}
	defer func() {
		_ = os.RemoveAll(tmp)
		// A package that failed on its first version leaves no folder.
		_ = os.Remove(parent)
	}()
	if err := update.ExtractArchive(archive, tmp); err != nil {
		return err
	}
	if got, err := manifestVersion(tmp); err != nil {
		return err
	} else if got != rel.version {
		return fmt.Errorf("the archive holds version %s, and its tag says %s", got, rel.version)
	}

	if err := s.unfit(pkg, rel.version, tmp); err != nil {
		return err
	}

	dest := filepath.Join(parent, rel.version)
	if err := os.Rename(tmp, dest); err != nil {
		// Another process, such as `loupe update`, may have installed it first.
		if _, statErr := os.Stat(dest); statErr == nil {
			return nil
		}

		return err
	}

	return nil
}

// manifestVersion reads the version a package folder says it holds.
func manifestVersion(dir string) (string, error) {
	data, err := os.ReadFile(filepath.Join(dir, manifestPath))
	if err != nil {
		return "", fmt.Errorf("the archive holds no %s", manifestPath)
	}
	var manifest struct {
		Version string `json:"version"`
	}
	if err := json.Unmarshal(data, &manifest); err != nil || manifest.Version == "" {
		return "", fmt.Errorf("%s has no version", manifestPath)
	}

	return manifest.Version, nil
}
