package cmd

import (
	"errors"
	"fmt"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/transcript"
	"github.com/spf13/cobra"
)

func newUsageBackfillWakesCmd() *cobra.Command {
	var o backfillOptions

	cmd := &cobra.Command{
		Use:   "backfill-wakes",
		Short: "Send what woke each resumed worker run to Loupe",
		Long: "Reads the worker_started lines of the bridge log and the Claude Code transcript " +
			"of each session. For each worker process that resumed a session, it finds the pause " +
			"before it: the time from the last assistant message before the process to its first " +
			"assistant message, and the tokens that first message read, read from the cache and " +
			"wrote to the cache. It sends them to Loupe with the trigger \"backfill\". " +
			"Loupe fills only a run that has no wake yet. No message text leaves the machine.\n\n" +
			"A pause is cold when the first message wrote more to the cache than it read as " +
			"input and from the cache. The command prints the count of pauses and of cold pauses " +
			"for each bucket: under 5 minutes, 5 minutes to 1 hour, and over 1 hour.\n\n" +
			"Run it on the machine that ran the bridge. It needs a Loupe version that takes " +
			"session wakes. Claude Code deletes a transcript after about 30 days, and the " +
			"command skips a session with no transcript.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			return backfillWakes(cmd, o)
		},
	}
	cmd.Flags().StringVar(&o.logFile, "log-file", "", "read the bridge log at this `path` and its dated files; empty uses bridge.log in your config directory")
	cmd.Flags().StringVar(&o.project, "project", "", "send only the sessions of the project with this `id`, as the bridge log names it")
	cmd.Flags().BoolVar(&o.dryRun, "dry-run", false, "print the counts of each bucket, and send nothing")

	return cmd
}

// The gap buckets of the wake report.
var gapBuckets = []struct {
	name  string
	below time.Duration
}{
	{"under 5m", 5 * time.Minute},
	{"5m-1h", time.Hour},
	{"over 1h", 0},
}

// gapTally counts the pauses of each bucket, and the cold ones.
type gapTally struct {
	gaps, cold [3]int
}

func (t *gapTally) add(g transcript.ResumeGap) {
	i := 0
	for i < len(gapBuckets)-1 && g.Gap() >= gapBuckets[i].below {
		i++
	}
	t.gaps[i]++
	if g.Cold() {
		t.cold[i]++
	}
}

func backfillWakes(cmd *cobra.Command, o backfillOptions) error {
	path := o.logFile
	if path == "" {
		path = defaultLogPath()
	}
	sessions, err := readWorkerSessions(path, o.project)
	if err != nil {
		return err
	}
	dir, err := transcript.ConfigDir()
	if err != nil {
		return err
	}
	var client *api.Client
	if !o.dryRun {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		client = apiClient(cfg)
	}

	out := cmd.OutOrStdout()
	var tally gapTally
	failed := 0
	for _, s := range sessions {
		wakes, count, skip, err := sessionWakes(dir, s, &tally)
		switch {
		case err != nil:
			fmt.Fprintf(out, "failed %s: %v\n", s.label(), err)
			failed++

			continue
		case skip != "":
			fmt.Fprintf(out, "skipped %s: %s\n", s.label(), skip)

			continue
		case o.dryRun:
			fmt.Fprintf(out, "would send %s: %d wakes\n", s.label(), count)

			continue
		}

		if err := client.ReportSessionWakes(cmd.Context(), s.project, s.id, wakes); err != nil {
			fmt.Fprintf(out, "failed %s: %v\n", s.label(), err)
			failed++

			continue
		}
		fmt.Fprintf(out, "sent %s: %d wakes\n", s.label(), count)
	}
	for i, b := range gapBuckets {
		fmt.Fprintf(out, "pauses %s: %d, cold %d\n", b.name, tally.gaps[i], tally.cold[i])
	}
	if failed > 0 {
		return fmt.Errorf("%d of the sessions failed", failed)
	}

	return nil
}

// sessionWakes is the wake of each worker process of the session, nil for a
// process with no pause before it, and how many wakes there are. It adds the
// pauses to tally only for a session it can send.
func sessionWakes(dir string, s *workerSession, tally *gapTally) ([]*api.Wake, int, string, error) {
	if s.skip != "" {
		return nil, 0, s.skip, nil
	}
	path, err := transcript.Find(dir, s.id)
	if errors.Is(err, transcript.ErrNotFound) {
		return nil, 0, err.Error(), nil
	}
	if err != nil {
		return nil, 0, "", err
	}
	gaps, err := transcript.ResumeGaps(path, s.windows)
	if errors.Is(err, transcript.ErrUnmappable) {
		return nil, 0, err.Error(), nil
	}
	if err != nil {
		return nil, 0, "", err
	}

	wakes := make([]*api.Wake, len(gaps))
	count := 0
	for i, g := range gaps {
		if g == nil {
			continue
		}
		count++
		tally.add(*g)
		last := g.LastTurnAt
		wakes[i] = &api.Wake{
			Trigger: api.WakeBackfill, LastTurnAt: &last,
			FirstTurn: &api.TurnUsage{InputTokens: g.FirstTurn.Input, CacheReadTokens: g.FirstTurn.CacheRead, CacheWriteTokens: g.FirstTurn.CacheWrite},
		}
	}
	if count == 0 {
		return nil, 0, "no process of the session resumed a pause", nil
	}

	return wakes, count, "", nil
}
