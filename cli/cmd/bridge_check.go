package cmd

import (
	"fmt"

	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/spf13/cobra"
)

func newBridgeCheckCmd() *cobra.Command {
	var rulesPath, project string
	var asJSON bool

	cmd := &cobra.Command{
		Use:   "check",
		Short: "Check the rule file and list what each project cannot run",
		Long: "Loads the rule file as the bridge does, and makes no network call. It then lists " +
			"what the file gets wrong without failing to load: a before, refresh, reset or " +
			"command path that its project folder lacks, a work kind that one project lists and " +
			"another does not, and a project entry that runs without the variants of an " +
			"experiment.\n\n" +
			"The command exits with status 1 when the file does not load, and 0 otherwise.\n\n" +
			"With --project <slug> --json, it prints one JSON object for that project instead, " +
			"for discovery to read. The slug is the key of the project in the rule file. The object " +
			"holds these fields:\n" +
			"  project, dir, dirExists, rules: the slug, its folder, whether the folder exists, and the rule file.\n" +
			"  kinds: one item for each work kind that the project can run, with kind, source " +
			"(project or shared), action (worker, interactive or command), account, harness, and " +
			"commands. Each command has field (before, refresh, reset or run), argv, checked, " +
			"resolved and exists. checked is false for a command with a placeholder or a ~, which the " +
			"command does not resolve. A path with a slash resolves in the project folder, and a " +
			"bare name resolves on PATH.\n" +
			"  accounts: one item for each account that a kind uses, with name, harness, configDir " +
			"(empty for the default), configDirExists, and mcpServers. mcpServers lists the names " +
			"that the account config and the project .mcp.json declare. It misses the servers that " +
			"a plugin brings. mcpError holds the reason when a file cannot be read.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			path, err := rulesPathOr(rulesPath)
			if err != nil {
				return err
			}
			set, err := rules.Load(path, rules.Defaults{})
			if err != nil {
				return fmt.Errorf("rule file %s: %w", path, err)
			}
			if asJSON {
				return writeProjectCheck(cmd, set, path, project)
			}
			out := cmd.OutOrStdout()
			warnings := set.Warnings()
			if len(warnings) == 0 {
				fmt.Fprintf(out, "%s loads, and every project can run every kind it lists.\n", path)

				return nil
			}
			fmt.Fprintf(out, "%s loads, with %d warning(s):\n", path, len(warnings))
			for _, w := range warnings {
				fmt.Fprintf(out, "  %s\n", w)
			}

			return nil
		},
	}
	cmd.Flags().StringVar(&rulesPath, "rules", "", "read rules from this `path`; empty uses rules.yaml in your config directory")
	cmd.Flags().StringVar(&project, "project", "", "report on the project with this `slug`; needs --json")
	cmd.Flags().BoolVar(&asJSON, "json", false, "print the report of one project as JSON; needs --project")
	cmd.MarkFlagsRequiredTogether("project", "json")

	return cmd
}
