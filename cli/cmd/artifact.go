package cmd

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"mime"
	"os"
	"path/filepath"
	"sort"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/artifactcompile"
	_ "github.com/loupe-ac/loupe/cli/internal/claudedesign"
	"github.com/spf13/cobra"
)

// maxArtifactBytes is the request size the web server of Loupe accepts. A
// bigger push fails there, so the command refuses it before it reads on.
const maxArtifactBytes = 8 << 20

func newArtifactCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "artifact",
		Short: "Work with the artifacts of a project",
	}
	cmd.AddCommand(newArtifactPushCmd())

	return cmd
}

// newArtifactPushCmd stays hidden so an old plugin gets a clear message. The
// upload moved to `artifact create` and `artifact version create`.
func newArtifactPushCmd() *cobra.Command {
	return &cobra.Command{
		Use:                "push",
		Hidden:             true,
		DisableFlagParsing: true,
		Args:               cobra.ArbitraryArgs,
		RunE: func(*cobra.Command, []string) error {
			return &exitError{exitUsage, errors.New("`loupe artifact push` is gone. " +
				"Use `loupe artifact create <path> --name <name>` for a new artifact, " +
				"or `loupe artifact version create <artifact> <path>` for a new version. " +
				"Update the plugin if it still calls push")}
		},
	}
}

func plural(n int, one, many string) string {
	if n == 1 {
		return one
	}

	return many
}

// gapError names each gap, one line each.
func gapError(gaps []artifactcompile.Gap) error {
	lines := make([]string, 0, len(gaps))
	for _, g := range gaps {
		lines = append(lines, fmt.Sprintf("cannot compile %s, line %d: %s is not supported. Run again with --out <folder> to get the pages with each gap marked.", g.File, g.Line, g.Feature))
	}

	return errors.New(strings.Join(lines, "\n"))
}

// fileWithGapMarker gives the first text file that holds a gap marker.
func fileWithGapMarker(files []api.ArtifactFile) string {
	for _, f := range files {
		if bytes.Contains(f.Contents, []byte("loupe:gap")) && !bytes.Contains(f.Contents[:min(len(f.Contents), 8000)], []byte{0}) {
			return f.Path
		}
	}

	return ""
}

// checkOutFolder refuses a folder that holds anything.
func checkOutFolder(dir string) error {
	entries, err := os.ReadDir(dir)
	switch {
	case errors.Is(err, fs.ErrNotExist):
		return nil
	case err != nil:
		return fmt.Errorf("--out %s: %w", dir, err)
	case len(entries) > 0:
		return fmt.Errorf("--out %s is not empty. Name an empty or new folder", dir)
	}

	return nil
}

// writeCompiled writes the files of a compile run inside dir and stores
// nothing. A gap makes the command fail, after the files are written.
func writeCompiled(cmd *cobra.Command, dir string, res artifactcompile.Result) error {
	for _, f := range res.Files {
		if !filepath.IsLocal(filepath.FromSlash(f.Path)) {
			return fmt.Errorf("refusing to write %s outside %s", f.Path, dir)
		}
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	out := cmd.OutOrStdout()
	for _, f := range res.Files {
		full := filepath.Join(dir, filepath.FromSlash(f.Path))
		if err := os.MkdirAll(filepath.Dir(full), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(full, f.Contents, 0o644); err != nil {
			return err
		}
		fmt.Fprintf(out, "Wrote %s\n", full)
	}
	fmt.Fprintf(out, "Wrote %d %s to %s. Nothing is stored.\n", len(res.Files), plural(len(res.Files), "file", "files"), dir)
	if len(res.Gaps) == 0 {
		return nil
	}
	for _, g := range res.Gaps {
		fmt.Fprintf(cmd.ErrOrStderr(), "%s, line %d: %s (%s)\n", g.File, g.Line, g.Feature, g.Detail)
	}

	return fmt.Errorf("%d %s marked with a loupe:gap comment in %s. Finish each by hand, then push the folder", len(res.Gaps), plural(len(res.Gaps), "gap", "gaps"), dir)
}

// collectArtifactFiles reads the file, or the regular files of the folder.
func collectArtifactFiles(path string) ([]api.ArtifactFile, error) {
	info, err := os.Lstat(path)
	if err != nil {
		return nil, err
	}

	var files []api.ArtifactFile
	total := 0
	add := func(name, full string) error {
		f, err := os.Open(full)
		if err != nil {
			return err
		}
		defer f.Close()
		data, err := io.ReadAll(io.LimitReader(f, int64(maxArtifactBytes-total)+1))
		if err != nil {
			return err
		}
		total += len(data)
		if total > maxArtifactBytes {
			return fmt.Errorf("the files are larger than %d MiB, which Loupe refuses", maxArtifactBytes>>20)
		}
		files = append(files, api.ArtifactFile{Path: name, ContentType: artifactContentType(name), Contents: data})

		return nil
	}

	if !info.IsDir() {
		if !info.Mode().IsRegular() {
			return nil, fmt.Errorf("%s is not a regular file", path)
		}

		if err := add(filepath.Base(path), path); err != nil {
			return nil, err
		}

		return files, nil
	}

	err = filepath.WalkDir(path, func(full string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() {
			if d.Name() == ".git" && full != path {
				return filepath.SkipDir
			}

			return nil
		}
		if !d.Type().IsRegular() {
			return fmt.Errorf("%s is not a regular file", full)
		}
		rel, err := filepath.Rel(path, full)
		if err != nil {
			return err
		}

		return add(filepath.ToSlash(rel), full)
	})
	if err != nil {
		return nil, err
	}
	if len(files) == 0 {
		return nil, fmt.Errorf("%s holds no file to store", path)
	}
	sort.Slice(files, func(i, j int) bool { return files[i].Path < files[j].Path })

	return files, nil
}

// artifactContentType is the type the server keeps for a file. A name with an
// unknown extension is sent as bytes.
func artifactContentType(name string) string {
	if t := mime.TypeByExtension(strings.ToLower(filepath.Ext(name))); t != "" {
		return t
	}

	return "application/octet-stream"
}
