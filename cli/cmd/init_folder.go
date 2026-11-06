package cmd

import (
	"context"
	"errors"
	"fmt"
	"io"
	"strings"
	"time"

	"github.com/spf13/cobra"
)

const (
	folderRoot    = "root"
	folderCurrent = "current"
)

// initFolder returns the folder that init sets up: dir, or the repository root
// when the answer is the root. It asks nothing at the root or outside git.
func initFolder(cmd *cobra.Command, dir, choice string) (string, error) {
	out := cmd.OutOrStdout()
	root, below, ok := repositoryPlace(cmd.Context(), dir)
	switch {
	case !ok:
		step(out, "Folder", "skipped", dir+" is not in a git repository")

		return dir, nil
	case !below:
		step(out, "Folder", "skipped", dir+" is the repository root")

		return dir, nil
	}

	if choice == "" {
		if !isTerminal(cmd.InOrStdin()) {
			return "", fmt.Errorf("this folder is below the repository root %s: pass --folder root to set up the root, or --folder current to set up this folder", root)
		}
		var err error
		if choice, err = askFolder(out, cmd.InOrStdin(), dir, root); err != nil {
			return "", err
		}
	}

	if choice == folderRoot {
		step(out, "Folder", "done", "set up the repository root "+root)

		return root, nil
	}
	step(out, "Folder", "done", "set up "+dir+", below the repository root "+root)
	fmt.Fprintln(out, "Warning: a bridge worker starts at the repository root, so it cannot find this setup.")

	return dir, nil
}

// askFolder asks which folder to set up. Enter picks the root.
func askFolder(out io.Writer, in io.Reader, dir, root string) (string, error) {
	fmt.Fprintf(out, "This folder is below the repository root.\n  1) the repository root %s (default)\n  2) this folder %s\n", root, dir)
	r := lineReader(in)
	for {
		fmt.Fprint(out, "Which folder do you want to set up? [1/2]: ")
		line, err := r.ReadString('\n')
		if err != nil && (!errors.Is(err, io.EOF) || line == "") {
			if errors.Is(err, io.EOF) {
				return "", errors.New("no folder was chosen: pass --folder root or --folder current")
			}

			return "", err
		}
		switch strings.TrimSpace(line) {
		case "", "1":
			return folderRoot, nil
		case "2":
			return folderCurrent, nil
		}
		fmt.Fprintln(out, "Type 1 or 2.")
	}
}

// repositoryPlace reports the root of the repository that holds dir and whether
// dir is below it. ok is false outside a git repository. The prefix test needs
// no path compare, so a symlinked path still reads as the root.
func repositoryPlace(ctx context.Context, dir string) (root string, below, ok bool) {
	ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	prefix, err := initExec(ctx, dir, nil, "git", "rev-parse", "--show-prefix")
	if err != nil {
		return "", false, false
	}
	if strings.TrimSpace(string(prefix)) == "" {
		return dir, false, true
	}
	top, err := initExec(ctx, dir, nil, "git", "rev-parse", "--show-toplevel")
	if err != nil || strings.TrimSpace(string(top)) == "" {
		return "", false, false
	}

	return strings.TrimSpace(string(top)), true, true
}
