package cmd

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"mime"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/artifactcompile"
	_ "github.com/ubermuda/loupe/cli/internal/claudedesign"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/projectfile"
)

// maxArtifactBytes is the request size the web server of Loupe accepts. A
// bigger push fails there, so the command refuses it before it reads on.
const maxArtifactBytes = 8 << 20

// artifactPushTimeout bounds one push, files included.
const artifactPushTimeout = 2 * time.Minute

func newArtifactCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "artifact",
		Short: "Work with the artifacts of a project",
	}
	cmd.AddCommand(newArtifactPushCmd())

	return cmd
}

// artifactPushOptions are the flags of `loupe artifact push`.
type artifactPushOptions struct {
	name, artifact, card, sourceLink, notes, project, out string
}

func newArtifactPushCmd() *cobra.Command {
	var o artifactPushOptions

	cmd := &cobra.Command{
		Use:   "push <file-or-folder>",
		Short: "Store a file or a folder as an artifact, or as a new version of one",
		Long: "Uploads a file, or every file of a folder, to the artifact library of a project. " +
			"With --name it makes a new artifact. With --artifact it adds a new version to an " +
			"existing one, and Loupe keeps the old versions.\n\n" +
			"A file is stored under its base name. A folder is stored with the path of each file " +
			"relative to the folder, and the command skips a .git folder. It refuses an empty " +
			"folder, a file that is not a regular file, and a push of more than 8 MiB.\n\n" +
			"Only an interactive session stores an artifact. The command sends the id of the " +
			"session that runs it, from LOUPE_SESSION_ID, CLAUDE_CODE_SESSION_ID or " +
			"CODEX_THREAD_ID, and Loupe refuses a store from a worker run.\n\n" +
			"--card links the artifact to a card, by its number or its id. The project comes from " +
			"--project, else from " + projectfile.Name + " in this directory, else from the single " +
			"project your login covers.\n\n" +
			"It prints the artifact id, the version number, and the stable link and the link of " +
			"this version. Run `loupe login` first.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			return pushArtifact(cmd, args[0], o)
		},
	}
	cmd.Flags().StringVar(&o.name, "name", "", "the name of a new artifact")
	cmd.Flags().StringVar(&o.artifact, "artifact", "", "the `id` of an existing artifact, to store a new version of it")
	cmd.Flags().StringVar(&o.card, "card", "", "link the artifact to this card, by `number` or id")
	cmd.Flags().StringVar(&o.sourceLink, "source-link", "", "where the files came from, such as a design canvas link")
	cmd.Flags().StringVar(&o.notes, "notes", "", "what changed in this version")
	cmd.Flags().StringVar(&o.out, "out", "", "write the compiled files to this empty or new `folder` and store nothing")
	cmd.Flags().StringVar(&o.project, "project", "", "project id to store in (else "+projectfile.Name+" in this directory)")

	return cmd
}

func pushArtifact(cmd *cobra.Command, path string, o artifactPushOptions) error {
	if o.out == "" && (o.name == "") == (o.artifact == "") {
		return errors.New("pass --name for a new artifact, or --artifact for a new version of an existing one, and not both")
	}
	if o.out != "" {
		if err := checkOutFolder(o.out); err != nil {
			return err
		}
	}
	files, err := collectArtifactFiles(path)
	if err != nil {
		return err
	}
	compiled, err := artifactcompile.Run(files, artifactcompile.Options{})
	if err != nil {
		return err
	}
	files = compiled.Files
	if o.out != "" {
		return writeCompiled(cmd, o.out, compiled)
	}
	if len(compiled.Gaps) > 0 {
		return gapError(compiled.Gaps)
	}
	if name := fileWithGapMarker(files); name != "" {
		return fmt.Errorf("%s still holds a loupe:gap comment. Finish that gap and remove the comment, then push again", name)
	}

	cfg, err := config.Load()
	if err != nil {
		return err
	}
	client := apiClient(cfg)

	ctx, cancel := context.WithTimeout(cmd.Context(), artifactPushTimeout)
	defer cancel()

	handle, err := artifactProject(ctx, client, o.project, cmd.ErrOrStderr())
	if err != nil {
		return err
	}
	stored, err := client.PushArtifact(ctx, handle, api.ArtifactPush{
		Name:       o.name,
		ArtifactID: o.artifact,
		SourceLink: o.sourceLink,
		Notes:      o.notes,
		Card:       strings.TrimPrefix(o.card, "#"),
		Session:    mcpSession(),
		Files:      files,
	})
	if err != nil {
		return err
	}

	out := cmd.OutOrStdout()
	fmt.Fprintf(out, "Stored version %d of artifact %s (%d %s)\n", stored.VersionNumber, stored.ArtifactID, len(files), plural(len(files), "file", "files"))
	fmt.Fprintf(out, "Link:         %s\n", artifactLink(cfg.BaseURL, stored.Path))
	fmt.Fprintf(out, "Version link: %s\n", artifactLink(cfg.BaseURL, stored.VersionPath))

	return nil
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

// artifactLink joins the instance address and a path the server answered.
func artifactLink(base, path string) string {
	return strings.TrimRight(base, "/") + path
}

// artifactProject gives the project to store in: the flag, else the project
// file, else the one project the login covers.
func artifactProject(ctx context.Context, client *api.Client, flag string, notes io.Writer) (string, error) {
	project, err := mcpProject(flag, notes)
	if err != nil {
		return "", err
	}
	if id := project(); id != "" {
		return id, nil
	}

	sites, err := client.Sites(ctx)
	if err != nil {
		return "", fmt.Errorf("list your projects: %w", err)
	}
	if len(sites) != 1 {
		return "", fmt.Errorf("your login covers %d projects, so name one with --project or write %s with `loupe init`", len(sites), projectfile.Name)
	}

	return sites[0].ID, nil
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
