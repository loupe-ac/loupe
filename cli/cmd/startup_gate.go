package cmd

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"os"
	"runtime"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/setupbuild"
	"github.com/loupe-ac/loupe/cli/internal/update"
)

// startupGate decides whether a bridge may start, from the CLI range the
// server states and the binary that runs.
type startupGate struct {
	version string
	// install is the install method of the binary, such as installHomebrew.
	install string
	// custom is a build with no release behind it: a development build, or a
	// setup built from a checkout. It warns and runs.
	custom bool
	// shared is a setup that runs the binary of the default setup, so it never
	// replaces that binary.
	shared     bool
	autoUpdate bool
	// updateNow installs the release that fits the range.
	updateNow func(ctx context.Context, cliRange string) error
	warn      func(msg string)
}

// check returns nil when the bridge may start. Every refusal is one sentence
// that names the fix.
func (g startupGate) check(ctx context.Context, cliRange string) error {
	if cliRange == "" {
		return nil
	}
	if update.Satisfies(g.version, cliRange) {
		return nil
	}
	if g.custom {
		g.warn(fmt.Sprintf("This loupe build (%s) is outside the range %s of the server, so it may fail. It runs anyway, because it is a development build.", orDev(g.version), cliRange))

		return nil
	}
	outside := fmt.Sprintf("This loupe CLI %s is outside the range %s of the server.", g.version, cliRange)
	switch {
	case update.Above(g.version, cliRange):
		return fmt.Errorf("%s It is newer than the server supports. Upgrade the server.", outside)
	case g.install == installHomebrew:
		return fmt.Errorf("%s Run: brew upgrade loupe", outside)
	case g.shared || !g.autoUpdate:
		return fmt.Errorf("%s Run: loupe update", outside)
	}
	if err := g.updateNow(ctx, cliRange); err != nil {
		return fmt.Errorf("%s It could not update itself: %w. Run: loupe update", outside, err)
	}

	return fmt.Errorf("%s It updated itself, so start the bridge again.", outside)
}

// newStartupGate fills the gate from the running binary and the rule file.
func newStartupGate(autoUpdate bool, warn func(string), out io.Writer) startupGate {
	setup := config.Current()
	exe := os.Executable
	g := startupGate{
		version:    version,
		install:    installMethod(exe),
		custom:     version == "" || !validVersion(version) || (setup.Source != config.SourceDefault && setupbuild.Built(setup.Dir)),
		shared:     setup.Source != config.SourceDefault,
		autoUpdate: autoUpdate,
		warn:       warn,
	}
	g.updateNow = func(ctx context.Context, cliRange string) error {
		self := selfUpdate{
			version:    version,
			goos:       runtime.GOOS,
			goarch:     runtime.GOARCH,
			apiBase:    update.GitHubAPI,
			hc:         &http.Client{Timeout: updateTimeout},
			executable: exe,
			serverRange: func(context.Context) (string, error) {
				return cliRange, nil
			},
		}

		return self.run(ctx, out)
	}

	return g
}

func validVersion(v string) bool {
	_, ok := update.ParseVersion(v)

	return ok
}

// checkStartup reads the server's versions and applies the gate.
func checkStartup(ctx context.Context, client *api.Client, gate startupGate) error {
	versions, err := client.CheckServer(ctx)
	if err != nil {
		return err
	}

	return gate.check(ctx, versions.CLIRange)
}
