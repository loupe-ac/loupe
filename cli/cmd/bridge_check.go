package cmd

import (
	"fmt"

	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/spf13/cobra"
)

func newBridgeCheckCmd() *cobra.Command {
	var rulesPath string

	cmd := &cobra.Command{
		Use:   "check",
		Short: "Check the rule file and list what each project cannot run",
		Long: "Loads the rule file as the bridge does, and makes no network call. It then lists " +
			"what the file gets wrong without failing to load: a before, refresh, reset or " +
			"command path that its project folder lacks, a work kind that one project lists and " +
			"another does not, and a project entry that runs without the variants of an " +
			"experiment.\n\n" +
			"The command exits with status 1 when the file does not load, and 0 otherwise.",
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

	return cmd
}
