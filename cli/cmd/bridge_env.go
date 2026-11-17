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
	previous := envallow.Pass()
	envallow.SetPass(next)
	unsetRemoved(log, previous)
	logDroppedEnv(log)
}

// unsetRemoved clears from the bridge process each variable that a passEnv
// pattern of the old list matched and no list allows now. A bridge-only name
// stays, because the bridge needs it. The process image keeps the old value
// until an update or a restart, so the log says that.
func unsetRemoved(log *slog.Logger, previous []string) {
	keep := append(envallow.Union(), envallow.Pass()...)
	var gone []string
	for _, entry := range os.Environ() {
		name, _, _ := strings.Cut(entry, "=")
		if envallow.Match(name, previous) && !envallow.Match(name, keep) {
			gone = append(gone, name)
		}
	}
	for _, name := range gone {
		os.Unsetenv(name)
	}
	if len(gone) > 0 {
		log.Warn("pass_env_removed_needs_restart", "names", gone,
			"message", "The bridge dropped these variables from its environment. The process image keeps them until loupe bridge update or a restart.")
	}
}
