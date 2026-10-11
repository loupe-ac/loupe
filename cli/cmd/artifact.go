package cmd

import (
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
	name, artifact, card, sourceLink, project string
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
	cmd.Flags().StringVar(&o.project, "project", "", "project id to store in (else "+projectfile.Name+" in this directory)")

	return cmd
}

func pushArtifact(cmd *cobra.Command, path string, o artifactPushOptions) error {
	if (o.name == "") == (o.artifact == "") {
		return errors.New("pass --name for a new artifact, or --artifact for a new version of an existing one, and not both")
	}
	files, err := collectArtifactFiles(path)
	if err != nil {
		return err
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
