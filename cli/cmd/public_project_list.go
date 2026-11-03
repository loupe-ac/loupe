package cmd

import (
	"encoding/json"
	"fmt"
	"strings"
	"text/tabwriter"

	"github.com/loupe-ac/loupe/cli/internal/output"
	"github.com/spf13/cobra"
)

// renderProjectList prints the star table of `project list` on a terminal. A
// star marks the project of the working directory. Any other format, and a
// list cut by --fields, print as the shared output does, with no star: a
// column change in a pipe would count as breaking after the beta.
func renderProjectList(cmd *cobra.Command, format output.Format, body []byte, fieldsSet bool) (bool, error) {
	if format != output.Table || fieldsSet {
		return false, nil
	}
	var list struct {
		Items []struct {
			ID   string `json:"id"`
			Slug string `json:"slug"`
			Name string `json:"name"`
		} `json:"items"`
	}
	if err := json.Unmarshal(body, &list); err != nil {
		return true, &exitError{1, fmt.Errorf("read the list of projects: %w", err)}
	}

	out := cmd.OutOrStdout()
	if len(list.Items) == 0 {
		fmt.Fprintln(out, "This credential reaches no project. Create one with `loupe init --name \"<name>\"`, or in the app.")

		return true, nil
	}

	current, source := listedProject(cmd.ErrOrStderr())
	marked := false
	w := tabwriter.NewWriter(out, 0, 0, 2, ' ', 0)
	fmt.Fprintln(w, "  ID\tSLUG\tNAME")
	for _, p := range list.Items {
		mark := " "
		if current != "" && strings.EqualFold(p.ID, current) {
			mark, marked = "*", true
		}
		slug := p.Slug
		if slug == "" {
			slug = "-"
		}
		fmt.Fprintf(w, "%s %s\t%s\t%s\n", mark, p.ID, slug, p.Name)
	}
	if err := w.Flush(); err != nil {
		return true, err
	}
	switch {
	case marked:
		fmt.Fprintf(out, "\n* marks the project that %s names.\n", source)
	case current != "":
		fmt.Fprintf(out, "\n%s names project %s, which your credential does not reach.\n", source, current)
	}
	noteNextPage(cmd.ErrOrStderr(), body)

	return true, nil
}
