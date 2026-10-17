package cmd

import (
	"errors"
	"slices"
	"sync/atomic"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

const addEntryText = "export:\n  action: command\n  run: [bin/export]\n"

// addEntryRequest is an open add-entry request about an inbox item. It names
// the bridge of the harness and carries the entry text as its prompt.
func addEntryRequest(n int, text string) api.WorkRequest {
	w := workRequest(n, 0, "add-entry", api.WorkRequestOpen)
	w.SubjectType, w.SubjectID, w.RuleID = "inbox-item", inboxItemID, "inbox:add-entry"
	w.Capability, w.Prompt, w.TargetBridgeID = rules.CapabilityAddEntry, text, testBridgeID

	return w
}

// withAddEntry gives the harness a rule file that accepts entries, a fake
// writer and a reload source that counts its loads.
func withAddEntry(t *testing.T, accept bool) (*harness, *fakeWork, *[]string, *atomic.Int32) {
	t.Helper()
	body := workRules
	if accept {
		body = "acceptEntries: true\n" + body
	}
	h := newHarnessWith(t, body, rules.Defaults{})
	f := h.withWork()
	var added []string
	loads := &atomic.Int32{}
	src := h.source(body)
	load := src.load
	src.load = func() (*rules.Set, error) {
		loads.Add(1)

		return load()
	}
	src.addEntry = func(text string) (func() error, error) {
		added = append(added, text)

		return func() error { return nil }, nil
	}
	h.router.source = src

	return h, f, &added, loads
}

// An add-entry request writes the entry, settles as done, and reloads.
func TestAnAddEntryRequestAddsTheEntryAndReloads(t *testing.T) {
	h, f, added, loads := withAddEntry(t, true)
	rec := h.states()

	h.offer(f, addEntryRequest(1, addEntryText))

	if !slices.Equal(*added, []string{addEntryText}) {
		t.Fatalf("added = %q", *added)
	}
	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " done"}) {
		t.Fatalf("results = %v", got)
	}
	if loads.Load() != 1 {
		t.Fatalf("loads = %d, want one reload", loads.Load())
	}
	if sent := rec.states(); len(sent) != 0 {
		t.Fatalf("reports = %+v, want none", sent)
	}
	if got := h.heldClaims(); len(got) != 0 {
		t.Fatalf("claims after the result = %v", got)
	}
}

// A failed reload takes the entry out again and refuses, so the inbox never
// says Added for an entry the bridge does not run.
func TestAFailedReloadUndoesTheEntryAndRefuses(t *testing.T) {
	h, f, _, _ := withAddEntry(t, true)
	h.router.source.load = func() (*rules.Set, error) { return nil, errors.New("broken") }
	undone := 0
	h.router.source.addEntry = func(string) (func() error, error) {
		return func() error { undone++; return nil }, nil
	}

	h.offer(f, addEntryRequest(1, addEntryText))

	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " refused " + rules.RefuseReloadFailed}) {
		t.Fatalf("results = %v", got)
	}
	if undone != 1 {
		t.Fatalf("undo ran %d times, want once", undone)
	}
}

// A retry that finds the same entry in the file reloads and settles as done,
// so an add that wrote the file before the bridge stopped reports its result.
func TestARetriedAddEntryWithTheSameEntrySettlesAsDone(t *testing.T) {
	h, f, _, loads := withAddEntry(t, true)
	h.router.source.addEntry = func(string) (func() error, error) { return nil, rules.ErrEntryPresent }

	h.offer(f, addEntryRequest(1, addEntryText))

	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " done"}) {
		t.Fatalf("results = %v", got)
	}
	if loads.Load() != 1 {
		t.Fatalf("loads = %d, want one reload", loads.Load())
	}
}

// An add waits for a reload that runs, rather than refusing.
func TestAnAddEntryWaitsForARunningReload(t *testing.T) {
	h, f, added, loads := withAddEntry(t, true)
	w := addEntryRequest(1, addEntryText)
	f.mu.Lock()
	f.requests[w.WorkRequestID] = w
	f.mu.Unlock()
	h.router.reloadMu.Lock()

	h.router.takeWork(w, workFromEvent)
	time.Sleep(50 * time.Millisecond)
	if len(*added) != 0 {
		h.router.reloadMu.Unlock()
		t.Fatal("the add wrote the file while a reload ran")
	}
	h.router.reloadMu.Unlock()
	h.router.wg.Wait()

	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " done"}) {
		t.Fatalf("results = %v", got)
	}
	if loads.Load() != 1 {
		t.Fatalf("loads = %d, want one reload", loads.Load())
	}
}

// A refusal settles with its reason code and reloads nothing.
func TestAnAddEntryRequestIsRefusedWithAReasonCode(t *testing.T) {
	for name, tc := range map[string]struct {
		accept bool
		err    error
		reason string
	}{
		"not accepted":  {false, nil, rules.RefuseNotAccepted},
		"entry invalid": {true, &rules.EntryRefusal{Reason: rules.RefuseEntryInvalid, Err: errors.New("x")}, rules.RefuseEntryInvalid},
		"entry exists":  {true, &rules.EntryRefusal{Reason: rules.RefuseEntryExists, Err: errors.New("x")}, rules.RefuseEntryExists},
		"file changed":  {true, &rules.EntryRefusal{Reason: rules.RefuseFileChanged, Err: errors.New("x")}, rules.RefuseFileChanged},
		"write failed":  {true, &rules.EntryRefusal{Reason: rules.RefuseWriteFailed, Err: errors.New("x")}, rules.RefuseWriteFailed},
		"other error":   {true, errors.New("disk"), rules.RefuseWriteFailed},
	} {
		t.Run(name, func(t *testing.T) {
			h, f, added, loads := withAddEntry(t, tc.accept)
			h.router.source.addEntry = func(text string) (func() error, error) {
				*added = append(*added, text)

				return nil, tc.err
			}

			h.offer(f, addEntryRequest(1, addEntryText))

			if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " refused " + tc.reason}) {
				t.Fatalf("results = %v, want refused %s", got, tc.reason)
			}
			if !tc.accept && len(*added) != 0 {
				t.Fatalf("wrote %q with acceptEntries off", *added)
			}
			if loads.Load() != 0 {
				t.Fatalf("loads = %d, want none", loads.Load())
			}
		})
	}
}

// A second offer of the same request adds nothing. The request starts no
// agent, whatever the work map holds.
func TestARepeatedAddEntryOfferDoesNothing(t *testing.T) {
	h, f, added, _ := withAddEntry(t, true)
	f.gate = make(chan struct{})
	w := addEntryRequest(1, addEntryText)

	h.router.takeWork(w, workFromEvent)
	h.router.takeWork(w, workFromHeartbeat)
	close(f.gate)
	h.router.wg.Wait()

	if len(*added) != 1 || len(f.claimed()) != 1 || len(f.settled()) != 1 {
		t.Fatalf("added = %q, claims = %v, results = %v", *added, f.claimed(), f.settled())
	}
	if len(h.router.queue) != 0 || len(h.worker.recorded()) != 0 {
		t.Fatal("the request started work")
	}
}

// A request that names no bridge, or another bridge, is left alone.
func TestAnAddEntryRequestNeedsThisBridgeAsItsTarget(t *testing.T) {
	for name, target := range map[string]string{"none": "", "other": "0192f3a1-0000-4d3e-8f10-a2b3c4d5e6f7"} {
		t.Run(name, func(t *testing.T) {
			h, f, added, _ := withAddEntry(t, true)
			w := addEntryRequest(1, addEntryText)
			w.TargetBridgeID = target

			h.offer(f, w)

			if len(*added) != 0 || len(f.claimed()) != 0 {
				t.Fatalf("added = %q, claims = %v", *added, f.claimed())
			}
		})
	}
}
