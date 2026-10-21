package cmd

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/outbound"
)

var outboxNow = time.Date(2026, 10, 12, 9, 0, 0, 0, time.UTC)

func newTestOutbox(t *testing.T) *outbox {
	t.Helper()

	return &outbox{
		dir:      filepath.Join(t.TempDir(), "outbox"),
		baseURL:  "https://loupe.test",
		bridgeID: testBridge,
		now:      func() time.Time { return outboxNow },
	}
}

// A kept outcome report reads back with every field, including the null half
// of the pairs that its own JSON encoding always sends.
func TestAKeptReportRoundTrips(t *testing.T) {
	box := newTestOutbox(t)
	report := failedReport()
	report.FailureReason = nil

	if err := box.keep(testProject, "run-1", report); err != nil {
		t.Fatal(err)
	}
	kept, bad, err := box.list()
	if err != nil || len(bad) != 0 || len(kept) != 1 {
		t.Fatalf("kept = %v, bad = %v, err = %v", kept, bad, err)
	}
	got := kept[0]
	if got.Handle != testProject || got.RunID != "run-1" || got.Rule != "plan" || !got.KeptAt.Equal(outboxNow) {
		t.Fatalf("kept = %+v", got.keptReport)
	}
	r := got.Report
	if r.State != api.RunFailed || r.Output != report.Output || r.CardNumber != 87 || r.Rule != "plan" ||
		r.ExitCode == nil || *r.ExitCode != 1 || r.HasResult == nil || *r.HasResult || r.FailureReason != nil ||
		!r.EndedAt.Equal(report.EndedAt) || !r.StartedAt.Equal(report.StartedAt) {
		t.Fatalf("report = %+v", r)
	}
}

func TestAnEmptyOutboxListsNothing(t *testing.T) {
	kept, bad, err := newTestOutbox(t).list()

	if err != nil || len(kept) != 0 || len(bad) != 0 {
		t.Fatalf("kept = %v, bad = %v, err = %v", kept, bad, err)
	}
}

func TestAnUnreadableOutboxFileStaysAndIsNamed(t *testing.T) {
	box := newTestOutbox(t)
	if err := os.MkdirAll(box.dir, 0o755); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(box.dir, "broken.json")
	if err := os.WriteFile(path, []byte("{"), 0o600); err != nil {
		t.Fatal(err)
	}

	kept, bad, err := box.list()

	if err != nil || len(kept) != 0 || len(bad) != 1 || bad[0] != path {
		t.Fatalf("kept = %v, bad = %v, err = %v", kept, bad, err)
	}
}

// The same state of the same run keeps one file.
func TestAKeptReportOfTheSameRunStateReplacesTheFirst(t *testing.T) {
	box := newTestOutbox(t)

	for range 2 {
		if err := box.keep(testProject, "run-1", failedReport()); err != nil {
			t.Fatal(err)
		}
	}
	kept, _, _ := box.list()

	if len(kept) != 1 {
		t.Fatalf("kept = %d files", len(kept))
	}
}

// lossReports builds a runReports with an outbox, and a queue that tells each
// report Lost with reason when its send fails.
func lossReports(t *testing.T, client runReportClient) (*runReports, *outbox, *syncBuffer) {
	t.Helper()
	reports, log := newTestRunReports(client)
	box := newTestOutbox(t)
	reports.outbox = box

	return reports, box, log
}

func lose(report outbound.Report, reason outbound.LossReason) {
	if report.Lost != nil {
		report.Lost(reason)
	}
}

// A closing report that the queue lost as unreachable goes to the outbox.
func TestAClosingReportThatIsLostUnreachableIsKept(t *testing.T) {
	reports, box, log := lossReports(t, &fakeRunClient{})

	lose(reports.state(testProject, "run-1", failedReport()), outbound.Unreachable)

	kept, _, _ := box.list()
	if len(kept) != 1 || kept[0].Report.State != api.RunFailed {
		t.Fatalf("kept = %v", kept)
	}
	if countEvents(t, log, "report_kept") != 1 {
		t.Fatalf("log = %s", log.String())
	}
}

// A refused report would be refused again on every connect, so it is not kept.
func TestARefusedReportIsNotKept(t *testing.T) {
	reports, box, _ := lossReports(t, &fakeRunClient{})

	lose(reports.state(testProject, "run-1", failedReport()), outbound.Refused)

	if kept, _, _ := box.list(); len(kept) != 0 {
		t.Fatalf("kept = %v", kept)
	}
}

// An open state is not final. The next state replaces it.
func TestAnOpenStateIsNotKept(t *testing.T) {
	reports, box, _ := lossReports(t, &fakeRunClient{})
	running := failedReport()
	running.State = api.RunRunning

	report := reports.state(testProject, "run-1", running)
	lose(report, outbound.Unreachable)

	if report.Lost != nil {
		t.Fatal("an open state has a Lost callback")
	}
	if kept, _, _ := box.list(); len(kept) != 0 {
		t.Fatalf("kept = %v", kept)
	}
}

func TestAKeptReportWithNoOutboxIsDropped(t *testing.T) {
	reports, _ := newTestRunReports(&fakeRunClient{})

	if report := reports.state(testProject, "run-1", failedReport()); report.Lost != nil {
		t.Fatal("a bridge with no outbox set a Lost callback")
	}
}

// replayed runs replayKept and sends each queued report.
func replayed(reports *runReports) {
	reports.replayKept(func(r outbound.Report) { _, _ = r.Send(context.Background()) })
}

func TestAKeptReportIsSentAndDeletedWhenLoupeTakesIt(t *testing.T) {
	client := &fakeRunClient{}
	reports, box, _ := lossReports(t, client)
	_ = box.keep(testProject, "run-1", failedReport())

	replayed(reports)

	if len(client.puts) != 1 || client.puts[0] != api.RunFailed {
		t.Fatalf("puts = %v", client.puts)
	}
	if kept, _, _ := box.list(); len(kept) != 0 {
		t.Fatalf("kept = %v", kept)
	}
}

// Kept reports take one place in the queue however many there are, so a large
// outbox never fills the queue and crowds out the inventory behind it.
func TestManyKeptReportsTakeOnePlaceInTheQueue(t *testing.T) {
	client := &fakeRunClient{}
	reports, box, _ := lossReports(t, client)
	for i := range 300 {
		_ = box.keep(testProject, fmt.Sprintf("run-%d", i), failedReport())
	}
	var queued []outbound.Report
	reports.replayKept(func(r outbound.Report) { queued = append(queued, r) })

	if len(queued) != 1 {
		t.Fatalf("queued = %d, want 1", len(queued))
	}
	if _, err := queued[0].Send(context.Background()); err != nil {
		t.Fatal(err)
	}
	if len(client.puts) != 300 {
		t.Fatalf("puts = %d, want 300", len(client.puts))
	}
	if kept, _, _ := box.list(); len(kept) != 0 {
		t.Fatalf("kept = %d", len(kept))
	}
}

// The walk stops at the first report Loupe does not answer, and its retry
// starts from that report.
func TestAKeptReplayStopsAtTheFirstUnansweredReport(t *testing.T) {
	var calls int
	client := &fakeRunClient{state: func(string) (bool, error) {
		calls++
		if calls == 2 {
			return false, errors.New("no such host")
		}

		return true, nil
	}}
	reports, box, _ := lossReports(t, client)
	for i := range 3 {
		box.now = func() time.Time { return outboxNow.Add(time.Duration(i) * time.Minute) }
		_ = box.keep(testProject, fmt.Sprintf("run-%d", i), failedReport())
	}
	box.now = func() time.Time { return outboxNow.Add(time.Hour) }
	var queued []outbound.Report
	reports.replayKept(func(r outbound.Report) { queued = append(queued, r) })

	if _, err := queued[0].Send(context.Background()); err == nil {
		t.Fatal("a failed walk reported success")
	}
	if kept, _, _ := box.list(); len(kept) != 2 || kept[0].RunID != "run-1" {
		t.Fatalf("kept = %v", kept)
	}
	if _, err := queued[0].Send(context.Background()); err != nil {
		t.Fatal(err)
	}
	if kept, _, _ := box.list(); len(kept) != 0 {
		t.Fatalf("kept = %v", kept)
	}
}

// A connect queues no second replay or inventory while the first still waits,
// so the reserve places of the queue always hold them.
func TestAConnectQueuesOneReplayAndOneInventoryAtATime(t *testing.T) {
	client := &fakeRunClient{}
	reports, box, _ := lossReports(t, client)
	_ = box.keep(testProject, "run-1", failedReport())
	var queued []outbound.Report
	enqueue := func(r outbound.Report) { queued = append(queued, r) }

	for range 2 {
		reports.replayKept(enqueue)
		reports.queueInventory(enqueue, testBridge, nil)
	}
	if len(queued) != 2 || !queued[0].Reserved || !queued[1].Reserved {
		t.Fatalf("queued = %d, want one reserved replay and one reserved inventory", len(queued))
	}

	if _, err := queued[0].Send(context.Background()); err != nil {
		t.Fatal(err)
	}
	queued[1].Lost(outbound.Unreachable)
	_ = box.keep(testProject, "run-2", failedReport())
	reports.replayKept(enqueue)
	reports.queueInventory(enqueue, testBridge, nil)
	if len(queued) != 4 {
		t.Fatalf("queued = %d, want a new replay and inventory once the first ones left", len(queued))
	}
}

// A connect while earlier reports fill the queue still gets the replay and the
// inventory through.
func TestAConnectToAFullQueueKeepsItsReplayAndInventory(t *testing.T) {
	h := newHarness(t)
	client := &fakeRunClient{}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	queue := outbound.New(ctx, h.router.log)
	defer queue.Close()
	h.router.reports = queue
	h.router.runs = newRunReports(client, h.router.log)
	box := newTestOutbox(t)
	box.bridgeID = h.router.bridgeID
	h.router.runs.outbox = box
	_ = box.keep(testProject, "run-1", failedReport())

	held, started := make(chan struct{}), make(chan struct{})
	queue.Enqueue(outbound.Report{Send: func(context.Context) (bool, error) {
		close(started)
		<-held

		return true, nil
	}})
	<-started
	filler := outbound.Report{Send: func(context.Context) (bool, error) { return true, nil }}
	for fillerFits(queue, filler) {
	}

	h.router.handler().OnConnect()
	h.router.wg.Wait()
	close(held)
	for deadline := time.Now().Add(5 * time.Second); queue.Pending() > 0 && time.Now().Before(deadline); {
		time.Sleep(time.Millisecond)
	}

	client.mu.Lock()
	defer client.mu.Unlock()
	if len(client.puts) != 1 || len(client.inventories) != 1 {
		t.Fatalf("puts = %v, inventories = %d, want the kept report and the inventory", client.puts, len(client.inventories))
	}
}

// fillerFits queues filler, and reports whether the queue took it.
func fillerFits(queue *outbound.Sender, filler outbound.Report) bool {
	before := queue.Pending()
	queue.Enqueue(filler)

	return queue.Pending() > before
}

func TestAnEmptyOutboxQueuesNothing(t *testing.T) {
	reports, _, _ := lossReports(t, &fakeRunClient{})
	var queued int
	reports.replayKept(func(outbound.Report) { queued++ })

	if queued != 0 {
		t.Fatalf("queued = %d", queued)
	}
}

func TestAKeptReportIsDeletedWhenLoupeRefusesIt(t *testing.T) {
	client := &fakeRunClient{state: func(string) (bool, error) {
		return false, fmt.Errorf("%w (HTTP 422)", api.ErrReportRefused)
	}}
	reports, box, _ := lossReports(t, client)
	_ = box.keep(testProject, "run-1", failedReport())

	replayed(reports)

	if kept, _, _ := box.list(); len(kept) != 0 {
		t.Fatalf("kept = %v", kept)
	}
}

func TestAKeptReportStaysWhileLoupeIsNotReachable(t *testing.T) {
	client := &fakeRunClient{state: func(string) (bool, error) { return false, errors.New("no such host") }}
	reports, box, _ := lossReports(t, client)
	_ = box.keep(testProject, "run-1", failedReport())

	replayed(reports)

	if kept, _, _ := box.list(); len(kept) != 1 {
		t.Fatalf("kept = %v", kept)
	}
}

func TestAKeptReportOlderThanTheLimitIsDeletedUnsent(t *testing.T) {
	client := &fakeRunClient{}
	reports, box, log := lossReports(t, client)
	box.now = func() time.Time { return outboxNow.Add(-keptReportAge - time.Hour) }
	_ = box.keep(testProject, "run-1", failedReport())
	box.now = func() time.Time { return outboxNow }

	replayed(reports)

	if len(client.puts) != 0 {
		t.Fatalf("puts = %v", client.puts)
	}
	if kept, _, _ := box.list(); len(kept) != 0 {
		t.Fatalf("kept = %v", kept)
	}
	if countEvents(t, log, "report_dropped") != 1 {
		t.Fatalf("log = %s", log.String())
	}
}

// A report made for another instance or bridge is never sent, and stays.
func TestAKeptReportOfAnotherInstanceStays(t *testing.T) {
	for name, edit := range map[string]func(*outbox){
		"base url":  func(o *outbox) { o.baseURL = "https://other.test" },
		"bridge id": func(o *outbox) { o.bridgeID = "another-bridge" },
	} {
		t.Run(name, func(t *testing.T) {
			client := &fakeRunClient{}
			reports, box, log := lossReports(t, client)
			_ = box.keep(testProject, "run-1", failedReport())
			edit(box)

			replayed(reports)

			if len(client.puts) != 0 {
				t.Fatalf("puts = %v", client.puts)
			}
			if kept, _, _ := box.list(); len(kept) != 1 {
				t.Fatalf("kept = %v", kept)
			}
			if countEvents(t, log, "report_kept_skipped") != 1 {
				t.Fatalf("log = %s", log.String())
			}
		})
	}
}

// orderQueue records what the router queues on a connect, in order.
type orderQueue struct {
	mu    sync.Mutex
	order []string
}

func (q *orderQueue) Enqueue(r outbound.Report) {
	q.mu.Lock()
	defer q.mu.Unlock()
	q.order = append(q.order, fmt.Sprintf("%s/%d", r.Rule, r.Card))
}

func (*orderQueue) SendLatest(string, func(context.Context) error, func(error)) {}
func (*orderQueue) Pending() int                                                { return 0 }
func (*orderQueue) Close()                                                      {}

// On a connect, the kept reports go into the queue before the inventory.
func TestKeptReportsGoBeforeTheInventory(t *testing.T) {
	h := newHarness(t)
	queue := &orderQueue{}
	h.router.reports = queue
	h.router.runs = newRunReports(&fakeRunClient{}, h.router.log)
	box := newTestOutbox(t)
	box.bridgeID = h.router.bridgeID
	h.router.runs.outbox = box
	_ = box.keep(testProject, "run-1", failedReport())

	h.router.handler().OnConnect()
	h.router.wg.Wait()

	if len(queue.order) != 2 || queue.order[0] != keptRule+"/0" || queue.order[1] != "/0" {
		t.Fatalf("order = %v, want the kept report, then the inventory", queue.order)
	}
}
