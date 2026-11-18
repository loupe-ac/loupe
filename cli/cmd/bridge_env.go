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
// execs this binary in place. A marker skips the exec only when the actual
// environment already holds no disallowed name, so an inherited marker cannot
// keep a secret. The marker value lists the dropped names, never a value.
func restartFiltered(path string) error {
	pass := rules.PassEnvOf(path)
	patterns := append(envallow.Union(), pass...)
	dropped := envallow.Dropped(os.Environ(), append(slices.Clone(patterns), envallow.FilteredMarker))
	if _, marked := os.LookupEnv(envallow.FilteredMarker); marked && len(dropped) == 0 {
		startupPass = pass
		envallow.SetPass(startupPass)

		return nil
	}
	names := dropped
	if v := os.Getenv(envallow.FilteredMarker); v != "" {
		names = append(strings.Split(v, ","), dropped...)
	}
	env := append(envallow.Filter(os.Environ(), patterns), envallow.FilteredMarker+"="+strings.Join(names, ","))
	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("find the bridge binary: %w", err)
	}
	if err := execBinary(exe, os.Args, env); err != nil {
		return fmt.Errorf("restart the bridge on the filtered environment: %w", err)
	}

	return nil
}

// handoverEnviron is the environment of the bridge image that an update execs.
// It drops each name that the live passEnv list no longer allows, and keeps
// the marker with the dropped names added, so the new image need not restart.
func handoverEnviron() []string {
	environ := os.Environ()
	patterns := append(envallow.Union(), envallow.Pass()...)
	dropped := envallow.Dropped(environ, append(patterns, envallow.FilteredMarker))
	if len(dropped) == 0 {
		return environ
	}
	names := dropped
	if v := os.Getenv(envallow.FilteredMarker); v != "" {
		names = append(strings.Split(v, ","), dropped...)
	}

	return append(envallow.Filter(environ, patterns), envallow.FilteredMarker+"="+strings.Join(names, ","))
}

// droppedEnv gives the sorted names that a worker of the harness loses: the
// names the restart removed from the bridge, and the names of the bridge
// environment that the filter of that harness drops now, such as a bridge-only
// name or a passEnv name that a reload removed.
func droppedEnv(harness string) []string {
	seen := map[string]bool{}
	if v := os.Getenv(envallow.FilteredMarker); v != "" {
		for _, name := range strings.Split(v, ",") {
			seen[name] = true
		}
	}
	for _, name := range envallow.DroppedFor(harness) {
		seen[name] = true
	}
	delete(seen, envallow.FilteredMarker)
	names := make([]string, 0, len(seen))
	for name := range seen {
		names = append(names, name)
	}
	slices.Sort(names)

	return names
}

// logDroppedEnv names the variables that each harness does not get.
func logDroppedEnv(log *slog.Logger) {
	log.Info("worker_env_dropped",
		"claude_code", droppedEnv(envallow.HarnessClaude),
		"codex", droppedEnv(envallow.HarnessCodex))
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
	envallow.SetPass(next)
	logDroppedEnv(log)
}

// removedPassEnv gives the sorted names of the bridge environment that the
// live passEnv list allows and the next list, with the base lists, does not.
// The process image keeps such a value, and ps -E shows it to a worker, so a
// reload that removes it must not take effect. A restart does.
func removedPassEnv(next []string) []string {
	live := append(envallow.Union(), envallow.Pass()...)
	keep := append(envallow.Union(), next...)
	var gone []string
	for _, entry := range os.Environ() {
		name, _, _ := strings.Cut(entry, "=")
		if envallow.Match(name, live) && !envallow.Match(name, keep) && !slices.Contains(gone, name) {
			gone = append(gone, name)
		}
	}
	slices.Sort(gone)

	return gone
}
