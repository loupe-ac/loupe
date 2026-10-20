package update

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"strings"
)

// PanelAppName is the Live review app, as a macOS archive holds it at its root.
const PanelAppName = "Live review.app"

// noPanelMarker records that the archive of a version holds no panel, so no
// later check downloads that archive again to look.
const noPanelMarker = "no-panel"

// ErrPanelTooBig reports an archive whose panel is larger than the caps allow.
var ErrPanelTooBig = errors.New("the panel in the archive is too large")

// The caps stop an archive that unpacks to far more than a real panel. They
// are variables so a test can lower them.
var (
	maxPanelBytes   int64 = 200 << 20
	maxPanelEntries       = 10000
)

// PanelDir is the folder that holds the panel of version, X.Y.Z, under the
// config directory.
func PanelDir(configDir, version string) string {
	return filepath.Join(configDir, "live-review", version)
}

// PanelSettled reports that the panel of version is installed, or that its
// archive is known to hold none.
func PanelSettled(configDir, version string) bool {
	dir := PanelDir(configDir, version)
	for _, name := range []string{PanelAppName, noPanelMarker} {
		if _, err := os.Stat(filepath.Join(dir, name)); err == nil {
			return true
		}
	}

	return false
}

// MarkNoPanel records that the archive of version holds no panel.
func MarkNoPanel(configDir, version string) error {
	return WriteFile(filepath.Join(PanelDir(configDir, version), noPanelMarker), nil, 0o644)
}

// ExtractPanel unpacks the entries under Live review.app/ of a tar.gz into a
// temporary folder inside destDir, then renames the result to
// destDir/Live review.app. It replaces an app that is there. It returns false
// and changes nothing when the archive holds no panel. Entries outside the
// app are skipped, but a path that is absolute or holds .. rejects the whole
// archive.
func ExtractPanel(archive []byte, destDir string) (bool, error) {
	gz, err := gzip.NewReader(bytes.NewReader(archive))
	if err != nil {
		return false, fmt.Errorf("open the archive: %w", err)
	}
	defer gz.Close()
	tr := tar.NewReader(gz)

	var tmp, staging string
	defer func() {
		if tmp != "" {
			os.RemoveAll(tmp)
		}
	}()
	var entries int
	var total int64
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return false, fmt.Errorf("read the archive: %w", err)
		}
		inner, inApp, err := panelEntryPath(hdr.Name)
		if err != nil {
			return false, err
		}
		if !inApp {
			continue
		}
		if entries++; entries > maxPanelEntries {
			return false, fmt.Errorf("%w: more than %d entries", ErrPanelTooBig, maxPanelEntries)
		}
		if hdr.Typeflag == tar.TypeReg {
			if total += hdr.Size; hdr.Size < 0 || total > maxPanelBytes {
				return false, fmt.Errorf("%w: more than %d bytes", ErrPanelTooBig, maxPanelBytes)
			}
		}
		if tmp == "" {
			if err := os.MkdirAll(destDir, 0o755); err != nil {
				return false, err
			}
			if tmp, err = os.MkdirTemp(destDir, ".panel-"); err != nil {
				return false, err
			}
			staging = filepath.Join(tmp, PanelAppName)
			if err := os.Mkdir(staging, 0o755); err != nil {
				return false, err
			}
		}
		if err := extractPanelEntry(tr, hdr, staging, inner); err != nil {
			return false, err
		}
	}
	if tmp == "" {
		return false, nil
	}
	if err := checkSymlinks(staging); err != nil {
		return false, err
	}

	return true, installPanel(destDir, tmp, staging)
}

// panelEntryPath returns the path of name inside the app, "" for the app
// itself. inApp is false for an entry outside the app.
func panelEntryPath(name string) (inner string, inApp bool, err error) {
	if strings.HasPrefix(name, "/") || strings.Contains(name, "\\") {
		return "", false, fmt.Errorf("the archive holds the unsafe path %q", name)
	}
	for seg := range strings.SplitSeq(name, "/") {
		if seg == ".." {
			return "", false, fmt.Errorf("the archive holds the unsafe path %q", name)
		}
	}
	clean := path.Clean(name)
	if clean == PanelAppName {
		return "", true, nil
	}
	rest, ok := strings.CutPrefix(clean, PanelAppName+"/")

	return rest, ok, nil
}

func extractPanelEntry(tr *tar.Reader, hdr *tar.Header, staging, inner string) error {
	if inner == "" {
		if hdr.Typeflag != tar.TypeDir {
			return fmt.Errorf("the archive holds %s as something other than a folder", PanelAppName)
		}

		return nil
	}
	target := filepath.Join(staging, filepath.FromSlash(inner))
	switch hdr.Typeflag {
	case tar.TypeDir:
		return ensurePanelDir(staging, inner)
	case tar.TypeReg:
		if err := ensurePanelDir(staging, path.Dir(inner)); err != nil {
			return err
		}
		f, err := os.OpenFile(target, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
		if err != nil {
			return err
		}
		if _, err := io.Copy(f, tr); err != nil {
			f.Close()

			return fmt.Errorf("read the archive: %w", err)
		}
		if err := f.Chmod(fs.FileMode(hdr.Mode) & 0o777); err != nil {
			f.Close()

			return err
		}

		return f.Close()
	case tar.TypeSymlink:
		if !symlinkStaysInside(inner, hdr.Linkname) {
			return fmt.Errorf("the archive holds the symlink %q to %q, which leaves the app", hdr.Name, hdr.Linkname)
		}
		if err := ensurePanelDir(staging, path.Dir(inner)); err != nil {
			return err
		}

		return os.Symlink(hdr.Linkname, target)
	default:
		return fmt.Errorf("the archive holds %q, which is not a file, folder or symlink", hdr.Name)
	}
}

// symlinkStaysInside reports that a symlink at inner, with this target,
// resolves to a place inside the app by its path alone.
func symlinkStaysInside(inner, target string) bool {
	if target == "" || strings.HasPrefix(target, "/") {
		return false
	}
	resolved := path.Join(path.Dir(inner), target)

	return resolved != ".." && !strings.HasPrefix(resolved, "../")
}

// ensurePanelDir creates each folder of rel under root. It refuses to go
// through a symlink, so no entry can write outside the app.
func ensurePanelDir(root, rel string) error {
	cur := root
	for seg := range strings.SplitSeq(rel, "/") {
		if seg == "." || seg == "" {
			continue
		}
		cur = filepath.Join(cur, seg)
		info, err := os.Lstat(cur)
		if errors.Is(err, fs.ErrNotExist) {
			if err := os.Mkdir(cur, 0o755); err != nil {
				return err
			}

			continue
		}
		if err != nil {
			return err
		}
		if !info.IsDir() {
			return fmt.Errorf("the archive writes through %q, which is not a folder", seg)
		}
	}

	return nil
}

// checkSymlinks rejects a symlink that really resolves outside the app. A
// chain of links can leave the app where each link looks safe on its own.
func checkSymlinks(staging string) error {
	real, err := filepath.EvalSymlinks(staging)
	if err != nil {
		return err
	}

	return filepath.WalkDir(staging, func(p string, d fs.DirEntry, err error) error {
		if err != nil || d.Type()&fs.ModeSymlink == 0 {
			return err
		}
		resolved, err := filepath.EvalSymlinks(p)
		if errors.Is(err, fs.ErrNotExist) {
			return nil
		}
		if err != nil {
			return err
		}
		if resolved != real && !strings.HasPrefix(resolved, real+string(filepath.Separator)) {
			return fmt.Errorf("the archive holds a symlink that leaves the app: %s", strings.TrimPrefix(p, staging+"/"))
		}

		return nil
	})
}

// installPanel moves staging to destDir/Live review.app. An app that is there
// waits in tmp until the new one is in place.
func installPanel(destDir, tmp, staging string) error {
	final := filepath.Join(destDir, PanelAppName)
	old := filepath.Join(tmp, "old")
	moved := false
	if _, err := os.Lstat(final); err == nil {
		if err := os.Rename(final, old); err != nil {
			return err
		}
		moved = true
	}
	if err := os.Rename(staging, final); err != nil {
		if moved {
			err = errors.Join(err, os.Rename(old, final))
		}

		return err
	}

	return nil
}
