package update

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"errors"
	"fmt"
	"io"
	"path"
	"strings"
)

// ErrArchiveTooBig reports an archive that unpacks to more than the caps allow.
var ErrArchiveTooBig = errors.New("the archive is too large")

// The caps stop a skill package archive that unpacks to far more than a real
// package. They are variables so a test can lower them.
var (
	maxArchiveBytes   int64 = 50 << 20
	maxArchiveEntries       = 5000
)

// ExtractArchive unpacks a tar.gz into root, which must exist and be empty. Its
// entries sit at the root of the archive, and a leading "./" is allowed. A path
// that is absolute or holds "..", a link that leaves root, an entry that is no
// file, folder or symlink, and an archive above the caps fail with
// ErrArchiveUnsafe or ErrArchiveTooBig. The caller removes root after a failure.
func ExtractArchive(archive []byte, root string) error {
	gz, err := gzip.NewReader(bytes.NewReader(archive))
	if err != nil {
		return fmt.Errorf("open the archive: %w", err)
	}
	defer gz.Close()
	tr := tar.NewReader(gz)

	var entries int
	var total int64
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return fmt.Errorf("read the archive: %w", err)
		}
		inner, err := archiveEntryPath(hdr.Name)
		if err != nil {
			return err
		}
		if inner == "" {
			continue
		}
		if entries++; entries > maxArchiveEntries {
			return fmt.Errorf("%w: more than %d entries", ErrArchiveTooBig, maxArchiveEntries)
		}
		if hdr.Typeflag == tar.TypeReg {
			if total += hdr.Size; hdr.Size < 0 || total > maxArchiveBytes {
				return fmt.Errorf("%w: more than %d bytes", ErrArchiveTooBig, maxArchiveBytes)
			}
		}
		if err := extractEntry(tr, hdr, root, inner); err != nil {
			return err
		}
	}

	return checkSymlinks(root)
}

// archiveEntryPath returns the path of name inside the archive, and "" for the
// root folder itself.
func archiveEntryPath(name string) (string, error) {
	if strings.HasPrefix(name, "/") || strings.Contains(name, "\\") {
		return "", unsafeEntry("the path %q", name)
	}
	for seg := range strings.SplitSeq(name, "/") {
		if seg == ".." {
			return "", unsafeEntry("the path %q", name)
		}
	}
	clean := path.Clean(name)
	if clean == "." {
		return "", nil
	}

	return clean, nil
}
