package cmd

import (
	"fmt"
	"os"
	"slices"
	"strings"
	"syscall"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/setupbuild"
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
	flag, target := earlyParse(args)
	setup, err := config.Resolve(flag)
	if err != nil {
		return err
	}
	if err := enforceSetup(setup, target); err != nil {
		return err
	}
	if setup.Source != config.SourceDefault {
		return os.Setenv(config.EnvVar, setup.Dir)
	}

	return nil
}

// enforceSetup runs after the setup is known and before Cobra handles any
// flag. In a built setup, it checks that bin/loupe is the build the recipe
// checked, and hands the run over to that file when another file runs. A run
// from a pin skips the hash, because the pin was checked when it was made. The
// setup-probe command skips the check, because the recipe runs it on a build
// that is not installed yet.
func enforceSetup(setup config.Setup, target string) error {
	if setup.Source == config.SourceDefault || target == "setup-probe" || !setupbuild.Built(setup.Dir) {
		return nil
	}
	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("locate the loupe binary: %w", err)
	}
	if setupbuild.InPin(setup.Dir, exe) {
		return nil
	}
	if _, err := setupbuild.Check(setup.Dir); err != nil {
		return err
	}
	bin := setupbuild.BinPath(setup.Dir)
	if sameFileAt(exe, bin) {
		return nil
	}

	return execBinary(bin, append([]string{bin}, os.Args[1:]...), withEnv(os.Environ(), config.EnvVar, setup.Dir))
}

func sameFileAt(a, b string) bool {
	ai, err := os.Stat(a)
	if err != nil {
		return false
	}
	bi, err := os.Stat(b)

	return err == nil && os.SameFile(ai, bi)
}

// withEnv sets name in env.
func withEnv(env []string, name, value string) []string {
	out := slices.DeleteFunc(slices.Clone(env), func(e string) bool { return strings.HasPrefix(e, name+"=") })

	return append(out, name+"="+value)
}

// execBinary replaces the process. Tests replace it.
var execBinary = func(path string, argv, env []string) error {
	return syscall.Exec(path, argv, env)
}

// earlyConfigFlag reads the value of -c/--config from args.
func earlyConfigFlag(args []string) string {
	flag, _ := earlyParse(args)

	return flag
}

// earlyParse reads the value of -c/--config from args with the rules Cobra
// uses, and names the command the arguments select. It parses on a throwaway
// command tree so the real one is not touched. A parse error is ignored: Cobra
// parses the same arguments again and reports it.
func earlyParse(args []string) (flag, command string) {
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

	if f := target.Flags().Lookup(configFlag); f != nil {
		flag = f.Value.String()
	}

	return flag, target.Name()
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
