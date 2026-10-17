package cmd

import (
	"context"
	"errors"
	"log/slog"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/stream"
)

// toolCallBatch is the most calls one tool call report carries.
const toolCallBatch = 500

// siteTTL is how long the bridge keeps the settings of the projects.
const siteTTL = 10 * time.Minute

// runReportClient is the part of the API client that carries run reports.
type runReportClient interface {
	ReportRunState(ctx context.Context, handle, runID string, report api.RunStateReport) (bool, error)
	ReportRunInventory(ctx context.Context, bridgeID string, runs []api.InventoryRun) error
	ReportInteractiveLaunch(ctx context.Context, handle, sessionID string, report api.InteractiveLaunchReport) (bool, error)
	ReportToolCalls(ctx context.Context, handle, runID string, batch api.ToolCallBatch) error
	PutRunOutput(ctx context.Context, handle, runID, bridgeID string, offset int64, text string) (api.RunOutputAnswer, error)
	Sites(ctx context.Context) ([]api.Site, error)
}

// runReports builds the reports of worker runs for the ordered queue.
type runReports struct {
	client runReportClient
	log    *slog.Logger
	// launchUnsupported is set by the first 404 of the interactive run endpoint.
	launchUnsupported atomic.Bool
	// toolCallsUnsupported logs the first 404 of the tool call endpoint once.
	// Each later batch still tries, because agent push can come back on.
	toolCallsUnsupported atomic.Bool

	// outbox keeps the closing state of a run that the queue could not
	// deliver. A nil one keeps nothing.
	outbox *outbox
	// replayWaiting and inventoryWaiting hold the token of the copy that
	// waits in the queue, or nil.
	replayWaiting    atomic.Pointer[byte]
	inventoryWaiting atomic.Pointer[byte]

	// sites caches the projects of the caller, read at sitesAt. now is
	// time.Now when nil, and tests set it.
	sitesMu sync.Mutex
	sites   []api.Site
	sitesAt time.Time
	now     func() time.Time
}

func newRunReports(client runReportClient, log *slog.Logger) *runReports {
	return &runReports{client: client, log: log}
}

// state is one state of the run runID, in the project handle.
func (s *runReports) state(handle, runID string, report api.RunStateReport) outbound.Report {
	r := outbound.Report{
		Card: report.CardNumber,
		Rule: report.Rule,
		Send: func(ctx context.Context) (bool, error) {
			return s.client.ReportRunState(ctx, handle, runID, report)
		},
	}
	if s.outbox != nil && api.IsClosing(report.State) {
		r.Lost = func(reason outbound.LossReason) {
			if reason != outbound.Unreachable {
				return
			}
			if err := s.outbox.keep(handle, runID, report); err != nil {
				s.log.Warn("report_keep_failed", "card", report.CardNumber, "rule", report.Rule, "error", err.Error())

				return
			}
			s.log.Info("report_kept", "card", report.CardNumber, "rule", report.Rule, "state", report.State)
		}
	}

	return r
}

// keptRule names the replay of the outbox in the log of the queue.
const keptRule = "kept-reports"

// replayKept queues one report that sends every kept report, oldest first, for
// a bridge that has just connected. One report takes one place in the queue
// however many files wait, so the inventory behind it is never crowded out. A
// file leaves the outbox when Loupe takes or refuses its report. The first one
// Loupe does not answer stops the walk, and the queue retries it from there.
func (s *runReports) replayKept(enqueue func(outbound.Report)) {
	if s.outbox == nil {
		return
	}
	if kept, bad, err := s.outbox.list(); err == nil && len(kept) == 0 && len(bad) == 0 {
		return
	}
	queueOnce(&s.replayWaiting, enqueue, outbound.Report{
		Rule: keptRule,
		Send: func(ctx context.Context) (bool, error) {
			return true, s.sendKept(ctx)
		},
	})
}

// queueInventory queues the inventory of a connect.
func (s *runReports) queueInventory(enqueue func(outbound.Report), bridgeID string, runs []api.InventoryRun) {
	queueOnce(&s.inventoryWaiting, enqueue, s.inventory(bridgeID, runs))
}

// queueOnce queues r in a reserve place, unless an earlier copy still waits.
// That copy lands first, and the reports queued after it land in order, so a
// second copy adds nothing. The copy stops waiting when its send starts.
func queueOnce(waiting *atomic.Pointer[byte], enqueue func(outbound.Report), r outbound.Report) {
	token := new(byte)
	if !waiting.CompareAndSwap(nil, token) {
		return
	}
	send, lost := r.Send, r.Lost
	r.Reserved = true
	r.Send = func(ctx context.Context) (bool, error) {
		waiting.CompareAndSwap(token, nil)

		return send(ctx)
	}
	r.Lost = func(reason outbound.LossReason) {
		waiting.CompareAndSwap(token, nil)
		if lost != nil {
			lost(reason)
		}
	}
	enqueue(r)
}

// sendKept walks the outbox once, and stops at the first report that Loupe
// does not answer.
func (s *runReports) sendKept(ctx context.Context) error {
	now := time.Now
	if s.outbox.now != nil {
		now = s.outbox.now
	}
	kept, bad, err := s.outbox.list()
	if err != nil {
		s.log.Warn("outbox_unreadable", "dir", s.outbox.dir, "error", err.Error())

		return nil
	}
	for _, path := range bad {
		s.log.Warn("outbox_file_unreadable", "file", path)
	}
	for _, k := range kept {
		switch {
		case now().Sub(k.KeptAt) > keptReportAge:
			s.log.Warn("report_dropped", "card", k.Report.CardNumber, "rule", k.Rule, "kept_at", k.KeptAt, "message", "kept report is older than the age limit")
			s.outbox.remove(k.path)
		case k.BaseURL != s.outbox.baseURL || k.BridgeID != s.outbox.bridgeID:
			s.log.Warn("report_kept_skipped", "file", k.path, "message", "kept report belongs to another instance or bridge")
		default:
			_, err := s.client.ReportRunState(ctx, k.Handle, k.RunID, k.Report)
			if errors.Is(err, api.ErrReportRefused) {
				s.log.Warn("report_failed", "card", k.Report.CardNumber, "rule", k.Rule, "error", err.Error())
			} else if err != nil {
				return err
			}
			s.outbox.remove(k.path)
		}
	}

	return nil
}

// inventory lists every run the bridge holds, across all its projects.
func (s *runReports) inventory(bridgeID string, runs []api.InventoryRun) outbound.Report {
	return outbound.Report{
		Send: func(ctx context.Context) (bool, error) {
			err := s.client.ReportRunInventory(ctx, bridgeID, runs)

			return err == nil, err
		},
	}
}

// launch is how the launch of the interactive session sessionID went. A server
// with no endpoint for it counts the report as delivered.
func (s *runReports) launch(handle, sessionID string, report api.InteractiveLaunchReport) outbound.Report {
	return outbound.Report{
		Card: report.CardNumber,
		Rule: report.WorkKind,
		Send: func(ctx context.Context) (bool, error) {
			if s.launchUnsupported.Load() {
				return true, nil
			}
			created, err := s.client.ReportInteractiveLaunch(ctx, handle, sessionID, report)
			if !errors.Is(err, api.ErrInteractiveRunsUnsupported) {
				return created, err
			}
			if s.launchUnsupported.CompareAndSwap(false, true) {
				s.log.Warn("interactive_runs_unsupported",
					"message", "Loupe has no interactive run endpoint, so the bridge does not report its launches",
				)
			}

			return true, nil
		},
	}
}

// toolCalls are the reports that carry the tool calls of the run runID, in
// batches of toolCallBatch. Only the last batch carries the timing, so a run
// with no call still sends one empty batch. The rows are built now, with the
// project settings read now, so a queued batch holds no unsent raw input.
func (s *runReports) toolCalls(ctx context.Context, handle, runID string, card int, rule string, calls []stream.Call, timing stream.Timing) []outbound.Report {
	rows := toolCallRows(calls, s.site(ctx, handle))
	n := max(1, (len(rows)+toolCallBatch-1)/toolCallBatch)
	reports := make([]outbound.Report, 0, n)
	for i := range n {
		part := rows[i*toolCallBatch : min((i+1)*toolCallBatch, len(rows))]
		var last *api.ToolTiming
		if i == n-1 {
			last = &api.ToolTiming{ToolTimeMs: timing.ToolTimeMs, IdleGapMs: timing.IdleGapMs}
		}
		reports = append(reports, outbound.Report{
			Card: card,
			Rule: rule,
			Send: func(ctx context.Context) (bool, error) {
				batch := api.ToolCallBatch{Calls: part, Timing: last}
				err := s.client.ReportToolCalls(ctx, handle, runID, batch)
				if !errors.Is(err, api.ErrToolCallsUnsupported) {
					return err == nil, err
				}
				if s.toolCallsUnsupported.CompareAndSwap(false, true) {
					s.log.Warn("tool_calls_unsupported",
						"message", "Loupe has no tool call endpoint, so the bridge does not send the tool calls of its runs",
					)
				}

				return true, nil
			},
		})
	}

	return reports
}

// toolCallRows are the calls as the server takes them. A call with no start
// time has no row the server takes, so it stays out.
func toolCallRows(calls []stream.Call, site api.Site) []api.ToolCall {
	rows := make([]api.ToolCall, 0, len(calls))
	for _, c := range calls {
		if c.StartedAt.IsZero() {
			continue
		}
		row := api.ToolCall{
			Seq: c.Seq, Tool: c.Tool, Kind: c.Kind, StartedAt: c.StartedAt, DurationMs: c.DurationMs, IsError: c.IsError,
			InSubagent: c.InSubagent, BackgroundID: c.BackgroundID, WaitsOn: c.WaitsOn,
			Signatures: stream.Signatures(c, site.SubcommandPrograms),
		}
		if site.CollectFullText {
			text := c.FullText
			row.FullText = &text
		}
		rows = append(rows, row)
	}

	return rows
}

// site is the project handle names, from a cache of siteTTL. A project the
// bridge cannot read gets the defaults: no full text and the default programs.
func (s *runReports) site(ctx context.Context, handle string) api.Site {
	s.sitesMu.Lock()
	defer s.sitesMu.Unlock()

	now := time.Now
	if s.now != nil {
		now = s.now
	}
	if s.sites == nil || now().Sub(s.sitesAt) >= siteTTL {
		sites, err := s.client.Sites(ctx)
		if err != nil {
			s.sites = nil

			return api.Site{}
		}
		s.sites, s.sitesAt = append([]api.Site{}, sites...), now()
	}
	for _, site := range s.sites {
		if strings.EqualFold(site.ID, handle) {
			return site
		}
	}

	return api.Site{}
}

// output sends one chunk of the output of the run runID.
func (s *runReports) output(ctx context.Context, handle, runID, bridgeID string, offset int64, text string) (api.RunOutputAnswer, error) {
	return s.client.PutRunOutput(ctx, handle, runID, bridgeID, offset, text)
}
