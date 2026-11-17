package cmd

import (
	"fmt"
	"log/slog"
	"os"
	"slices"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/envallow"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// startupPass is the passEnv list the bridge started with. A name added later
// cannot reach the process, because the restart dropped the variable.
var startupPass []string

// restartFiltered makes the bridge run on an environment that holds only the
// base lists of both harnesses and the passEnv names of the rule file. It
// execs this binary once, in place, and the marker keeps the new image from
// doing it again. The marker value lists the dropped names, never a value.
func restartFiltered(path string) error {
	if _, done := os.LookupEnv(envallow.FilteredMarker); done {
		startupPass = rules.PassEnvOf(path)
		envallow.SetPass(startupPass)

		return nil
	}
	pass := rules.PassEnvOf(path)
	patterns := append(envallow.Union(), pass...)
	dropped := envallow.Dropped(os.Environ(), patterns)
	env := append(envallow.Filter(os.Environ(), patterns), envallow.FilteredMarker+"="+strings.Join(dropped, ","))
	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("find the bridge binary: %w", err)
	}
	if err := execBinary(exe, os.Args, env); err != nil {
		return fmt.Errorf("restart the bridge on the filtered environment: %w", err)
	}

	return nil
}

// logDroppedEnv names the variables the restart left out of the bridge.
func logDroppedEnv(log *slog.Logger) {
	var names []string
	if v := os.Getenv(envallow.FilteredMarker); v != "" {
		names = strings.Split(v, ",")
	}
	log.Info("worker_env_dropped", "names", names)
}

// applyPassEnv takes the passEnv list of a reloaded rule file. A name that the
// bridge did not start with logs pass_env_needs_restart, because the variable
// is gone from the bridge process. An env file holds the value instead.
func applyPassEnv(log *slog.Logger, set *rules.Set) {
	next := set.PassEnv()
	for _, name := range next {
		if !slices.Contains(startupPass, name) {
			log.Warn("pass_env_needs_restart", "name", name,
				"message", "The bridge started without this name. Put the value in an env file and run loupe bridge reload, or restart the bridge.")
		}
	}
	logDroppedEnv(log)
	envallow.SetPass(next)
}
