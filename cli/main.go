// Command loupe is the Loupe command-line tool. Today it bridges
// submitted site reviews into a local Claude Code session; more subcommands will
// be added over time.
package main

import (
	"os"

	"github.com/loupe-ac/loupe/cli/cmd"
)

func main() {
	if err := cmd.Execute(); err != nil {
		cmd.PrintError(os.Stderr, err)
		if code, ok := cmd.ExitCode(err); ok {
			os.Exit(code)
		}
		os.Exit(1)
	}
}
