package cmd

import (
	"encoding/json"
	"fmt"
	"strings"

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
		if isTerminalWriter(out) {
			fmt.Fprintln(cmd.ErrOrStderr(), "This credential reaches no project. Create one with `loupe init --name \"<name>\"`, or in the app.")
		}
		noteNextPage(cmd.ErrOrStderr(), body)

		return true, nil
	}

	current, source := listedProject(cmd.ErrOrStderr())
	marked := false
	marks := make([]string, len(list.Items))
	rows := make([][]string, len(list.Items))
	for i, p := range list.Items {
		marks[i] = " "
		if current != "" && strings.EqualFold(p.ID, current) {
			marks[i], marked = "*", true
		}
		slug := p.Slug
		if slug == "" {
			slug = "-"
		}
		rows[i] = []string{p.ID, slug, p.Name}
	}
	width := terminalWidth(out)
	if width > 0 {
		width = max(width-len("* "), 1)
	}
	lines := output.TableLines([]string{"ID", "SLUG", "NAME"}, rows, width)
	fmt.Fprintln(out, "  "+lines[0])
	for i, line := range lines[1:] {
		fmt.Fprintln(out, marks[i]+" "+line)
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
