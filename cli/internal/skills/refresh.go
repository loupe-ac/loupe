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
// version whose requires the store cannot meet, stay out and give a reason. A
// dev build, whose cliVersion is empty, meets every minCli.
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
	// An install checks only what the new version needs directly, so the store
	// has the last word on what a worker can start with.
	r := Status(configDir, cliVersion, pkg)
	r.Installed = installed
	if reason != "" {
		r.State, r.Reason = StateFailed, reason
	}

	return r
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

	meta := readPackage(tmp)
	if meta.MinCli != "" && s.cliVersion != "" && compareVersions(s.cliVersion, meta.MinCli) < 0 {
		return heldBack{reason: fmt.Sprintf("%s %s needs CLI %s or later, and this CLI is %s", pkg, rel.version, meta.MinCli, s.cliVersion), permanent: true}
	}
	for _, dep := range slices.Sorted(maps.Keys(meta.Requires)) {
		want := meta.Requires[dep]
		if !slices.ContainsFunc(s.candidates(dep), func(c candidate) bool { return satisfies(c.Version, want) }) {
			return heldBack{reason: fmt.Sprintf("%s %s needs %s %s, and the store holds no such version", pkg, rel.version, dep, want)}
		}
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
