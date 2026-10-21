package cmd

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
	"github.com/loupe-ac/loupe/cli/internal/setupbuild"
	"github.com/loupe-ac/loupe/cli/internal/update"
	"github.com/spf13/cobra"
)

// reviewIOS is what `loupe review ios` needs from the machine; tests replace it.
type reviewIOS struct {
	goos       string
	version    string
	executable func() (string, error)
	configDir  func() (string, error)
	getwd      func() (string, error)
	// open starts the app with the argument list, with no shell.
	open func(args ...string) error
}

func defaultReviewIOS() reviewIOS {
	return reviewIOS{
		goos:       runtime.GOOS,
		version:    version,
		executable: os.Executable,
		configDir:  config.Dir,
		getwd:      os.Getwd,
		open: func(args ...string) error {
			out, err := exec.Command("open", args...).CombinedOutput()
			if err != nil {
				return fmt.Errorf("%w: %s", err, strings.TrimSpace(string(out)))
			}

			return nil
		},
	}
}

func newReviewIOSCmd() *cobra.Command {
	return newReviewIOSCmdWith(defaultReviewIOS())
}

func newReviewIOSCmdWith(env reviewIOS) *cobra.Command {
	return &cobra.Command{
		Use:   "ios",
		Short: "Open Live review for an app in the local iOS simulator",
		Long: "Opens the Live review panel for the current folder. The panel sits beside the " +
			"iOS simulator and saves notes with a screenshot to the Loupe project. The project " +
			"comes from .loupe.yaml in the current folder; without that file, the panel asks " +
			"you to pick one. This command needs macOS and the panel that ships with the " +
			"loupe release. Run loupe update, or reinstall loupe, when the panel is missing.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			return env.run(cmd)
		},
	}
}

func (env reviewIOS) run(cmd *cobra.Command) error {
	if env.goos != "darwin" {
		return errors.New("Live review for iOS needs macOS")
	}
	panel, err := env.findPanel()
	if err != nil {
		return err
	}
	dir, err := env.getwd()
	if err != nil {
		return fmt.Errorf("read the current folder: %w", err)
	}
	exe, err := env.executable()
	if err != nil {
		return fmt.Errorf("locate the loupe binary: %w", err)
	}
	if exe, err = filepath.Abs(exe); err != nil {
		return err
	}
	args := []string{"-n"}
	if setup := config.Current(); setup.Source != config.SourceDefault {
		pin, err := makeSetupPin()
		if err != nil {
			return err
		}
		if pin != "" {
			exe = filepath.Join(pin, "loupe")
		}
		args = append(args, "--env", config.EnvVar+"="+setup.Dir)
	}
	args = append(args, panel, "--args", "--loupe", exe, "--dir", dir)
	linked, err := setupLink(dir)
	if err != nil {
		return err
	}
	if linked != "" {
		args = append(args, "--project", linked)
	} else {
		file, err := projectfile.Load(dir)
		switch {
		case errors.Is(err, projectfile.ErrNoFile):
		case err != nil:
			return fmt.Errorf("read the project file: %w", err)
		case file.Project != "":
			args = append(args, "--project", file.Project)
		}
	}
	if err := env.open(args...); err != nil {
		return fmt.Errorf("could not open %s: %w", panel, err)
	}
	fmt.Fprintf(cmd.OutOrStdout(), "Opened Live review for %s.\n", dir)

	return nil
}

// findPanel returns the panel of the running version. A Homebrew install keeps
// it in libexec beside the binary. Any other install keeps it in the config
// directory.
func (env reviewIOS) findPanel() (string, error) {
	if setup := config.Current(); setup.Source != config.SourceDefault {
		return env.findSetupPanel(setup)
	}
	if panel, ok := libexecPanel(env.executable); ok {
		return panel, nil
	}
	libexec := "the libexec folder beside the binary"
	if exe, err := env.executable(); err == nil {
		if resolved, err := filepath.EvalSymlinks(exe); err == nil {
			exe = resolved
		}
		libexec = filepath.Join(filepath.Dir(exe), "..", "libexec", update.PanelAppName)
		libexec = filepath.Clean(libexec)
	}
	parsed, ok := update.ParseVersion(env.version)
	if !ok {
		return "", fmt.Errorf("this is a development build of loupe, which has no installed Live review panel; looked in %s", libexec)
	}
	dir, err := env.configDir()
	if err != nil {
		return "", err
	}
	installed := filepath.Join(update.PanelDir(dir, parsed.String()), update.PanelAppName)
	if isDir(installed) {
		return installed, nil
	}

	return "", fmt.Errorf("Live review is not installed; looked in %s and %s. Run loupe update, or reinstall loupe", libexec, installed)
}

// findSetupPanel finds the panel of a setup other than the default. A built
// setup keeps its panel in libexec beside bin/, and nothing else counts. A
// setup that runs the shared binary looks in the versioned panel folder of the
// setup, then of the default setup.
func (env reviewIOS) findSetupPanel(setup config.Setup) (string, error) {
	if setupbuild.Built(setup.Dir) {
		panel := filepath.Join(setup.Dir, "libexec", update.PanelAppName)
		if isDir(panel) {
			return panel, nil
		}

		return "", fmt.Errorf("the setup %s has no Live review panel at %s; run just cli-setup %s", setup.Dir, panel, setup.Dir)
	}
	parsed, ok := update.ParseVersion(env.version)
	if !ok {
		return "", fmt.Errorf("this is a development build of loupe, which has no installed Live review panel for the setup %s", setup.Dir)
	}
	defaultDir, err := config.DefaultDir()
	if err != nil {
		return "", err
	}
	var looked []string
	for _, dir := range []string{setup.Dir, defaultDir} {
		installed := filepath.Join(update.PanelDir(dir, parsed.String()), update.PanelAppName)
		if isDir(installed) {
			return installed, nil
		}
		looked = append(looked, installed)
	}

	return "", fmt.Errorf("Live review is not installed; looked in %s. Run loupe -c %s update", strings.Join(looked, " and "), setup.Dir)
}

// libexecPanel finds the panel a Homebrew formula installs in libexec, beside
// the folder of the binary. The binary is usually a symlink into the keg.
func libexecPanel(executable func() (string, error)) (string, bool) {
	exe, err := executable()
	if err != nil {
		return "", false
	}
	if resolved, err := filepath.EvalSymlinks(exe); err == nil {
		exe = resolved
	}
	panel := filepath.Clean(filepath.Join(filepath.Dir(exe), "..", "libexec", update.PanelAppName))

	return panel, isDir(panel)
}

func isDir(path string) bool {
	info, err := os.Stat(path)

	return err == nil && info.IsDir()
}
