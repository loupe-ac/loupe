package cmd

import (
	"fmt"
	"os"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/spf13/cobra"
)

// Execute runs the root command. main() is the only caller.
func Execute() error {
	root := newRootCmd()
	if err := resolveSetup(os.Args[1:]); err != nil {
		return err
	}

	return root.Execute()
}

// resolveSetup picks the setup before Cobra handles any flag, help and version
// included, and writes it into LOUPE_CONFIG so every child process follows it.
func resolveSetup(args []string) error {
	setup, err := config.Resolve(earlyConfigFlag(args))
	if err != nil {
		return err
	}
	if err := enforceSetup(setup, args); err != nil {
		return err
	}
	if setup.Source != config.SourceDefault {
		return os.Setenv(config.EnvVar, setup.Dir)
	}

	return nil
}

// enforceSetup runs after the setup is known and before the command runs.
// A setup may refuse to run here. It accepts every setup for now.
func enforceSetup(config.Setup, []string) error { return nil }

// earlyConfigFlag reads the value of -c/--config from args with the rules
// Cobra uses, on a throwaway command tree so the real one is not touched. A
// parse error is ignored: Cobra parses the same arguments again and reports it.
func earlyConfigFlag(args []string) string {
	probe := newRootCmd()
	probe.InitDefaultHelpCmd()
	target, rest, err := probe.Find(args)
	if err != nil || target == nil {
		target, rest = probe, args
	}
	target.InitDefaultHelpFlag()
	target.InitDefaultVersionFlag()
	target.FParseErrWhitelist.UnknownFlags = true
	_ = target.ParseFlags(rest)

	f := target.Flags().Lookup(configFlag)
	if f == nil {
		return ""
	}

	return f.Value.String()
}

const configFlag = "config"

func newRootCmd() *cobra.Command {
	root := &cobra.Command{
		Use:           "loupe",
		Short:         "Loupe command-line tools",
		Version:       versionString(),
		SilenceUsage:  true,
		SilenceErrors: true,
	}
	root.PersistentFlags().StringP(configFlag, "c", "", "use this setup `folder` instead of the default (else "+config.EnvVar+")")
	// Cobra's default template prefixes "loupe version", which the string
	// already carries, and it spans two lines.
	root.SetVersionTemplate("{{.Version}}\n")
	root.AddCommand(newLoginCmd(), newInitCmd(), newProjectCmd(), newMcpCmd(), newStatusCmd(), newBridgeCmd(), newAgentAccountCmd(), newCredentialCmd(), newPreviewTokenCmd(), newUsageCmd(), newArtifactCmd(), newEnvCmd(), newUpdateCmd(), newVersionCmd(), newReviewCmd(), newSetupProbeCmd())

	return root
}

func newSetupProbeCmd() *cobra.Command {
	return &cobra.Command{
		Use:    "setup-probe",
		Short:  "Print the resolved setup folder",
		Hidden: true,
		Args:   cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			_, err := fmt.Fprintln(cmd.OutOrStdout(), config.Current().Dir)

			return err
		},
	}
}
