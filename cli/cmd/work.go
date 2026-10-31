package cmd

import (
	"cmp"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"maps"
	"slices"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/event"
	"github.com/loupe-ac/loupe/cli/internal/outbound"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// workClient claims and settles work requests. *api.Client is one.
type workClient interface {
	ClaimWorkRequest(ctx context.Context, bridgeID, id, account string) (api.Claim, error)
	SettleWorkRequest(ctx context.Context, bridgeID, id, token, state, reason string) (string, error)
	HandBackWorkRequest(ctx context.Context, bridgeID, id, token string, h api.HandBack) (string, error)
}

// The channels a work request arrives on, as its log lines name them.
const (
	workFromEvent     = "event"
	workFromHeartbeat = "heartbeat"
)

// The fallback reasons of a refused work request.
const (
	workBlocked    = "blocked"
	workUnfinished = "unfinished"
	workFailed     = "failed"
	workTimeout    = "timeout"
)

// heldClaim is a work request the bridge claims or holds. token is "" while
// the claim call runs. running is on from the claim until the run ends, and
// a held claim with running off waits for its result to land. gone marks a
// request that was cancelled or expired while the claim call ran.
type heldClaim struct {
	token   string
	runID   string
	req     api.WorkRequest
	running bool
	gone    bool
	// abort ends the launch of an interactive claim until its command returns.
	abort context.CancelFunc
}

// abortableLocked is the context of the launch of p, which an end of its claim
// cancels. The caller holds mu.
func (r *router) abortableLocked(p pending) context.Context {
	c := r.claims[p.work.WorkRequestID]
	if c == nil {
		return context.Background()
	}
	ctx, cancel := context.WithCancel(context.Background())
	c.abort = cancel

	return ctx
}

// isWork reports whether p runs a work request.
func (p pending) isWork() bool {
	return p.work.WorkRequestID != ""
}

// followsWork reports whether p continues a run of a work request, as a
// person's resume or rerun asks.
func (p pending) followsWork() bool {
	return p.origin.Kind != ""
}

// workOrOrigin is the work request p runs or continues, and empty for the run
// of an event.
func (p pending) workOrOrigin() api.WorkRequest {
	if p.isWork() {
		return p.work
	}

	return p.origin
}

// workEvent is the event a run of the work request reports and logs with. It
// names the card of a card subject only.
func workEvent(w api.WorkRequest) event.Event {
	e := event.Event{
		Type: event.WorkRequestType, Subject: event.Subject{Type: "work-request", ID: w.WorkRequestID},
		ProjectID: w.ProjectID,
	}
	if w.OnCard() {
		e.CardID, e.CardNumber = w.SubjectID, w.CardNumber
	}

	return e
}

// matchPending matches a queued run again on set: a work request against the
// work map, and the run of a person's command against the entry of its kind.
// The match keeps the action.
func matchPending(set *rules.Set, p pending) (rules.Match, bool) {
	var m rules.Match
	switch {
	case p.isWork():
		m = set.MatchWork(p.work)
	case p.followsWork():
		m = set.MatchKind(p.origin)
		if _, reason := p.startedOn.settings(set, m.Run()); reason != "" {
			return m, false
		}
	default:
		return rules.Match{Skip: rules.NoRule}, false
	}

	return m, m.Skip == rules.Run && m.Action == p.action
}

// onWorkRequestEvent takes a bridge.work_request event.
func (r *router) onWorkRequestEvent(data []byte) {
	w, err := event.ParseWorkRequest(data)
	if err != nil {
		r.log.Warn("work_request_dropped", "reason", "malformed", "source", workFromEvent, "error", err.Error())

		return
	}
	r.takeWork(w, workFromEvent)
}

// takeWork acts on one checked work request. An open one is an offer, and any
// other state ends what the bridge holds of it.
func (r *router) takeWork(w api.WorkRequest, source string) {
	if r.workAPI == nil || r.bridgeID == "" {
		return
	}
	if !w.ForBridge(r.bridgeID) {
		r.log.Debug("work_request_dropped", append(workAttrs(w, source), "reason", "other_bridge")...)

		return
	}
	if w.State == api.WorkRequestOpen {
		r.offerWork(w, source)

		return
	}
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	r.mu.Lock()
	id := w.WorkRequestID
	removed := r.dropQueuedWorkLocked(id)
	c := r.claims[id]
	switch w.State {
	case api.WorkRequestCancelled, api.WorkRequestExpired:
		if c != nil {
			r.log.Info("work_request_ended", append(workAttrs(w, source), "state", w.State)...)
			r.endClaimLocked(id, c)
		}
	case api.WorkRequestDone, api.WorkRequestRefused:
		// A result that landed already dropped the claim. One whose post is
		// in flight drops it here, so the next heartbeat renews no settled
		// claim, and a launch that still runs aborts.
		if c != nil && c.token != "" && !c.running {
			r.endClaimLocked(id, c)
		}
	}
	dropped := r.dispatchLocked()
	r.mu.Unlock()
	if removed {
		r.log.Info("work_request_withdrawn", append(workAttrs(w, source), "state", w.State)...)
	}
	r.logDropped(dropped)
}

// offerWork queues an open offer that the work map runs. The queue drops a
// copy of an offer it holds already.
func (r *router) offerWork(w api.WorkRequest, source string) {
	if w.Capability == rules.CapabilityAddEntry {
		r.addEntry(w, source)

		return
	}
	set := r.rules()
	runCommand := w.Capability == rules.CapabilityRunCommand
	if runCommand {
		r.mu.Lock()
		known := r.knownWorkLocked(w.WorkRequestID)
		r.mu.Unlock()
		if known {
			return
		}
	}
	m := set.MatchWork(w)
	switch m.Skip {
	case rules.Run:
		if runCommand {
			if _, ok := r.worktreeDir(context.Background(), m.Dir, w.RunBranch()); !ok {
				r.refuseWork(w, rules.RefuseWorktreeMissing)

				return
			}
		}
	case rules.Unmapped:
		r.mu.Lock()
		first := r.markUnmappedLocked(w.ProjectID)
		r.mu.Unlock()
		if first {
			r.log.Warn("project_unmapped", "project", w.ProjectID)
		}

		return
	default:
		if reason := set.RunCommandRefusal(w); reason != "" {
			r.refuseWork(w, reason)

			return
		}
		// The heartbeat offers an open request again at each interval.
		level := slog.LevelInfo
		if source == workFromHeartbeat {
			level = slog.LevelDebug
		}
		r.log.Log(context.Background(), level, "work_request_skipped", append(workAttrs(w, source), "reason", "no_entry")...)

		return
	}
	p := pending{key: w.SubjectID, event: workEvent(w), work: w, set: set}
	p.account = matchedAccount(m, p)
	if p.account != "" && r.blocked.has(p.account) {
		r.skipBlocked(w, p.account, source)

		return
	}
	// A session that is not on this machine cannot resume, so the work starts fresh.
	if w.ResumeSessionID != "" && m.Action == "" && slices.ContainsFunc(resumeRuns(m), func(run rules.RunSettings) bool {
		return r.hasTranscript(w.ResumeSessionID, run)
	}) {
		p.event.SessionID = w.ResumeSessionID
	}
	p.apply(m)
	r.enqueue(p)
}

// refuseWork claims a request the bridge cannot run, and settles it as refused
// with the reason code. It starts no run and reports no worker run, as the
// refusal of an invalid claim does. A claim another bridge won, or a claim that
// fails, leaves nothing, and the next offer tries again.
func (r *router) refuseWork(w api.WorkRequest, reason string) {
	r.claimAndSettle(w, func(p pending) (string, string, func()) {
		r.log.Info("work_request_refused", append(about(p.event, p.rule), "reason", reason)...)

		return api.WorkRequestRefused, reason, nil
	})
}

// claimAndSettle claims a request the bridge answers without a run. After the
// claim, act decides the result and may return a function to call once the
// result is queued. It takes no pool slot and reports no worker run. A claim
// another bridge won, or a claim that fails, leaves nothing, and the next
// offer tries again.
func (r *router) claimAndSettle(w api.WorkRequest, act func(p pending) (state, reason string, after func())) {
	p := pending{key: w.SubjectID, event: workEvent(w), work: w, rule: rules.WorkRulePrefix + w.Kind, runID: config.NewUUID()}
	id := w.WorkRequestID
	r.mu.Lock()
	if r.frozen || r.shut() {
		r.mu.Unlock()

		return
	}
	if err := r.registerClaimLocked(p); err != nil {
		r.mu.Unlock()
		r.logClaimFailed(about(p.event, p.rule), err)

		return
	}
	r.wg.Add(1)
	r.mu.Unlock()
	go func() {
		defer r.wg.Done()

		claim, err := r.requestClaim(p)
		r.mu.Lock()
		c := r.claims[id]
		if err != nil || c == nil || c.gone || r.shut() {
			if c != nil {
				delete(r.claims, id)
			}
			r.mu.Unlock()
			if err != nil {
				r.logClaimFailed(about(p.event, p.rule), err)
			}

			return
		}
		c.token = claim.ClaimToken
		p.claimToken = claim.ClaimToken
		r.noteClaimsLocked()
		r.mu.Unlock()
		state, reason, after := act(p)
		r.settleWork(p, settlement{state: state, reason: reason})
		if after != nil {
			after()
		}
	}()
}

// addEntry answers a request to add a command entry to the rule file. The
// request is no work for an agent, so it never matches the work map. Only the
// bridge the request names takes it. The request is done only once a reload
// runs the entry. A failed reload takes the entry out again and refuses. A
// retry that finds the same entry in the file reloads, and is done.
func (r *router) addEntry(w api.WorkRequest, source string) {
	if w.TargetBridgeID == "" {
		r.log.Warn("work_request_skipped", append(workAttrs(w, source), "reason", "no_target")...)

		return
	}
	r.mu.Lock()
	known := r.knownWorkLocked(w.WorkRequestID)
	r.mu.Unlock()
	if known {
		return
	}
	r.claimAndSettle(w, func(p pending) (string, string, func()) {
		attrs := about(p.event, p.rule)
		if !r.rules().AcceptEntries() {
			r.log.Info("work_request_refused", append(attrs, "reason", rules.RefuseNotAccepted)...)

			return api.WorkRequestRefused, rules.RefuseNotAccepted, nil
		}
		r.reloadMu.Lock()
		defer r.reloadMu.Unlock()
		undo, err := r.addEntryToFile(w.Prompt)
		if errors.Is(err, rules.ErrEntryPresent) {
			undo, err = nil, nil
		}
		if err != nil {
			reason := rules.RefuseWriteFailed
			var refusal *rules.EntryRefusal
			if errors.As(err, &refusal) {
				reason = refusal.Reason
			}
			r.log.Warn("work_request_refused", append(attrs, "reason", reason, "error", err.Error())...)

			return api.WorkRequestRefused, reason, nil
		}
		if res := r.reloadLocked(r.workerContext(), r.source); !res.OK {
			failed := append(attrs, "stage", res.Stage, "problems", res.Problems)
			if undo != nil {
				if err := undo(); err != nil {
					failed = append(failed, "undo_error", err.Error())
				}
			}
			r.log.Warn("entry_reload_failed", failed...)

			return api.WorkRequestRefused, rules.RefuseReloadFailed, nil
		}
		r.log.Info("entry_added", attrs...)

		return api.WorkRequestDone, "", nil
	})
}

// addEntryToFile adds the entry text to the rule file.
func (r *router) addEntryToFile(text string) (func() error, error) {
	if r.source.addEntry == nil {
		return nil, &rules.EntryRefusal{Reason: rules.RefuseWriteFailed, Err: errors.New("the bridge cannot write the rule file")}
	}

	return r.source.addEntry(text)
}

// resumeRuns are the settings a run of m can take: those of its entry, and
// those of each variant, which resolves only at start.
func resumeRuns(m rules.Match) []rules.RunSettings {
	runs := []rules.RunSettings{m.Run()}
	if m.Experiment != nil {
		for _, v := range m.Experiment.Variants {
			runs = append(runs, m.Experiment.Settings(v))
		}
	}

	return runs
}

// workAttrs names a work request in a log line.
func workAttrs(w api.WorkRequest, source string) []any {
	return []any{"work_request", w.WorkRequestID, "kind", w.Kind, "subject_type", w.SubjectType, "card", w.CardNumber, "project", w.ProjectID, "source", source}
}

// knownWorkLocked reports whether the bridge queues, claims or holds the work
// request. The caller holds mu.
func (r *router) knownWorkLocked(id string) bool {
	return r.claims[id] != nil || slices.ContainsFunc(r.queue, func(p pending) bool { return p.work.WorkRequestID == id })
}

// dropQueuedWorkLocked removes the queued offer of the work request, and
// reports whether there was one. An offer holds nothing, and the server has
// no run of it, so it goes with no report. The caller holds mu.
func (r *router) dropQueuedWorkLocked(id string) bool {
	n := len(r.queue)
	r.queue = slices.DeleteFunc(r.queue, func(p pending) bool { return p.work.WorkRequestID == id })

	return len(r.queue) != n
}

// dropDeadWorkLocked removes the queued offers that the current set no longer
// runs, as a slug change or a gone project leaves them. The caller holds mu.
func (r *router) dropDeadWorkLocked() []pending {
	set := r.rules()
	var dropped []pending
	r.queue = slices.DeleteFunc(r.queue, func(p pending) bool {
		if !p.isWork() || set.MatchWork(p.work).Skip == rules.Run {
			return false
		}
		p.dropReason = api.DropRuleDead
		dropped = append(dropped, p)

		return true
	})
	if len(dropped) > 0 {
		r.noteBusyLocked()
		r.notePoolsLocked()
	}

	return dropped
}

// claimThenLocked claims the work request of p on its own goroutine, and on a
// claim calls run under mu. When the run must not start, release gives back
// what p holds. A run reports nothing before its claim, so a claim another
// bridge won leaves no run on the server. The caller holds mu.
func (r *router) claimThenLocked(p pending, run func(pending), release func()) {
	registered := r.registerClaimLocked(p)
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		var claim api.Claim
		err := registered
		if err == nil {
			claim, err = r.requestClaim(p)
		}
		r.mu.Lock()
		p, verdict := r.takeClaimLocked(p, claim, err, registered == nil)
		if verdict == claimRun {
			run(p)
		} else {
			release()
		}
		dropped := r.dispatchLocked()
		r.mu.Unlock()
		r.logDropped(dropped)
		if verdict == claimRefuse {
			r.settleWork(p, settlement{state: api.WorkRequestRefused, reason: workFailed})
		}
	}()
}

// The verdicts on a claim call.
const (
	claimRun = iota
	claimSkip
	claimRefuse
)

// errClaimCap marks a claim the bridge did not ask for, because it holds the
// most claims one heartbeat renews.
var errClaimCap = fmt.Errorf("the bridge holds %d claims, the most one heartbeat renews", api.MaxWorkClaims)

// errClaimHeld marks a claim the bridge did not ask for, because it claims or
// holds the request already.
var errClaimHeld = errors.New("the bridge claims or holds this work request already")

// registerClaimLocked records the claim before its call starts, so no second
// offer of the request runs or takes its place. The caller holds mu.
func (r *router) registerClaimLocked(p pending) error {
	id := p.work.WorkRequestID
	switch {
	case r.claims[id] != nil:
		return errClaimHeld
	case len(r.claims) >= api.MaxWorkClaims:
		return errClaimCap
	}
	if r.claims == nil {
		r.claims = map[string]*heldClaim{}
	}
	r.claims[id] = &heldClaim{runID: p.runID, req: p.work}

	return nil
}

// requestClaim asks the server for the claim, off mu.
func (r *router) requestClaim(p pending) (api.Claim, error) {
	id := p.work.WorkRequestID
	timeout := r.checkTimeout
	if timeout <= 0 {
		timeout = readTimeout
	}
	ctx, cancel := context.WithTimeout(r.workerContext(), timeout)
	defer cancel()

	return r.workAPI.ClaimWorkRequest(ctx, r.bridgeID, id, p.account)
}

// takeClaimLocked reads the answer to a claim. On a claim it keeps the token
// and reports the run as queued. registered says the claim of p made the
// entry, which a failure removes. The caller holds mu.
func (r *router) takeClaimLocked(p pending, claim api.Claim, err error, registered bool) (pending, int) {
	id := p.work.WorkRequestID
	attrs := about(p.event, p.rule)
	if err == nil {
		err = checkClaim(claim, p.work)
		if err != nil {
			r.log.Warn("work_request_claim_invalid", append(attrs, "error", err.Error())...)
			p.claimToken = claim.ClaimToken
			if c := r.claims[id]; c != nil {
				c.token = claim.ClaimToken
			}
			r.noteClaimsLocked()

			return p, claimRefuse
		}
	}
	c := r.claims[id]
	if err != nil {
		if registered && c != nil && c.token == "" {
			delete(r.claims, id)
		}
		r.logClaimFailed(attrs, err)

		return p, claimSkip
	}
	if c == nil || c.gone || r.shut() {
		// The request ended while the claim call ran, or the bridge shuts
		// down. The lease runs out on the server.
		delete(r.claims, id)
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "ended")...)

		return p, claimSkip
	}
	c.token, c.running = claim.ClaimToken, p.action != rules.ActionInteractive
	p.claimToken = claim.ClaimToken
	r.noteClaimsLocked()
	r.log.Info("work_request_claimed", attrs...)
	// A launch reports through its own endpoint, and never as a queued run.
	if p.action != rules.ActionInteractive {
		r.emitLocked(p, api.RunStateReport{State: api.RunQueued})
	}

	return p, claimRun
}

// checkClaim checks the request a claim answers with, and that it is the
// request the offer named.
func checkClaim(claim api.Claim, offer api.WorkRequest) error {
	w := claim.WorkRequest
	if err := event.CheckWorkRequest(&w); err != nil {
		return err
	}
	if w.WorkRequestID != offer.WorkRequestID || w.Kind != offer.Kind || w.ProjectID != offer.ProjectID ||
		w.SubjectType != offer.SubjectType || w.SubjectID != offer.SubjectID {
		return errors.New("the claim answers with another request than the offer")
	}

	return nil
}

// logClaimFailed says why a claim gave nothing. The heartbeat offers an open
// request again, so a failure that clears on its own needs no retry here.
func (r *router) logClaimFailed(attrs []any, err error) {
	var refusal *api.WorkRequestRefusal
	switch {
	case errors.Is(err, api.ErrWorkRequestAlreadyClaimed):
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "already_claimed")...)
	case errors.Is(err, api.ErrWorkRequestNotFound):
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "not_found")...)
	case errors.As(err, &refusal) && refusal.RateLimited():
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "rate_limited")...)
	case errors.Is(err, errClaimHeld):
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "duplicate")...)
	case errors.Is(err, api.ErrWorkRequestsUnsupported), errors.As(err, &refusal), errors.Is(err, errClaimCap):
		r.log.Warn("work_request_not_claimed", append(attrs, "reason", "refused", "error", err.Error())...)
	default:
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "error", "error", err.Error())...)
	}
}

// endClaimLocked ends what the bridge holds of a request that is no longer
// its own. It drops the claim and stops a run that has not ended, which then
// reports stopped and posts no result. A claim call in flight learns of it
// when it returns. The caller holds mu.
func (r *router) endClaimLocked(id string, c *heldClaim) {
	if c.token == "" {
		c.gone = true

		return
	}
	delete(r.claims, id)
	r.noteClaimsLocked()
	// A launch command that returned already leaves the session to the person.
	if c.abort != nil {
		c.abort()
	}
	if !c.running {
		return
	}
	if r.stops == nil {
		r.stops = map[string]bool{}
	}
	if r.stops[c.runID] {
		return
	}
	r.stops[c.runID] = true
	if run, ok := r.live[c.runID]; ok {
		r.stopLiveLocked(run)
	}
}

// loseClaims acts on the claims a heartbeat reply names lost, each with the
// token that heartbeat sent. A claim taken again since then holds another
// token, so the stale answer leaves it. A frozen router leaves them all, so
// the next image reads them in its own reply.
func (r *router) loseClaims(lost []api.WorkClaim) {
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.frozen {
		return
	}
	for _, l := range lost {
		c := r.claims[l.ID]
		if c == nil || c.token == "" || c.token != l.ClaimToken {
			continue
		}
		r.log.Warn("work_request_lost", append(workAttrs(c.req, workFromHeartbeat), "message", "the server no longer holds this claim for the bridge, so the bridge stops its run")...)
		r.endClaimLocked(l.ID, c)
	}
}

// lostOf names, with its token, each claim the heartbeat sent that the reply
// names lost.
func lostOf(sent []api.WorkClaim, ids []string) []api.WorkClaim {
	var out []api.WorkClaim
	for _, c := range sent {
		if slices.Contains(ids, c.ID) {
			out = append(out, c)
		}
	}

	return out
}

// noteWorkDeathLocked logs once when a kill ends the work of a project. The
// work map names no project, so nothing else shows that its offers now go
// unclaimed. before is the reason the work was dead before the kill. The
// caller holds mu.
func (r *router) noteWorkDeathLocked(set *rules.Set, id, before string) {
	slug := slugOf(set, id)
	if slug == "" || before != "" || !set.HasWork() {
		return
	}
	if reason := set.WorkDead(slug); reason != "" {
		r.log.Info("work_dead", "project", id, "project_slug", slug, "reason", reason,
			"message", fmt.Sprintf("the bridge claims no work request of project %s until you fix rules.yaml and run loupe bridge reload", slug))
	}
}

// workClaimsLocked lists the claims the next heartbeat renews, in id order.
// The caller holds mu.
func (r *router) workClaimsLocked() []api.WorkClaim {
	var out []api.WorkClaim
	for _, id := range slices.Sorted(maps.Keys(r.claims)) {
		if c := r.claims[id]; c.token != "" {
			out = append(out, api.WorkClaim{ID: id, ClaimToken: c.token})
		}
	}

	return out
}

// noteClaimsLocked hands the held claims to the heartbeat. The caller holds mu.
func (r *router) noteClaimsLocked() {
	if r.heartbeat == nil {
		return
	}
	r.heartbeat.setClaims(r.workClaimsLocked())
}

// dropClaimLocked forgets the claim of p, when p still holds it. The caller
// holds mu.
func (r *router) dropClaimLocked(id, token string) {
	if c := r.claims[id]; c != nil && c.token == token {
		delete(r.claims, id)
		r.noteClaimsLocked()
	}
}

// workResultLocked decides the result of a work run that ended, and marks its
// claim as settling. It answers false when the run posts none: the claim is
// gone, or the bridge stopped the run. The caller holds mu.
func (r *router) workResultLocked(p pending, res workerResult, shut bool) (settlement, bool) {
	if !p.isWork() || p.claimToken == "" {
		return settlement{}, false
	}
	id := p.work.WorkRequestID
	c := r.claims[id]
	if c == nil || c.token != p.claimToken {
		return settlement{}, false
	}
	if shut || res.killed {
		r.dropClaimLocked(id, p.claimToken)

		return settlement{}, false
	}
	c.running = false

	return backOf(p, workOutcome(res)), true
}

// workOutcome maps how a run ended to the result of its work request. A done
// result carries the reason of the structured result too, because a workflow
// rule can match on it. A run that a usage limit stopped gives the request back.
func workOutcome(res workerResult) settlement {
	refused := func(fallback string) settlement {
		if res.hasResult {
			return settlement{state: api.WorkRequestRefused, reason: cmp.Or(resultReason(res.reason), fallback)}
		}

		return settlement{state: api.WorkRequestRefused, reason: fallback}
	}
	if back := limitHandBack(res); back != nil {
		return settlement{state: api.WorkRequestOpen, back: back}
	}
	switch {
	case res.timedOut:
		return settlement{state: api.WorkRequestRefused, reason: workTimeout}
	case res.command && res.exitCode == 0:
		return settlement{state: api.WorkRequestDone}
	case res.command, res.before, res.resumeGone, res.err != nil, res.exitCode != 0, !res.hasResult:
		return refused(workFailed)
	case res.status == "blocked":
		return refused(workBlocked)
	case res.status == "unfinished":
		return refused(workUnfinished)
	}

	return settlement{state: api.WorkRequestDone, reason: resultReason(res.reason)}
}

// settleWork posts the result of a work request through the report queue,
// which retries a network failure. The claim stays renewed until the post
// settles, so a slow post never loses its lease. A post the queue gives up
// or drops ends the claim too.
func (r *router) settleWork(p pending, st settlement) {
	id, token := p.work.WorkRequestID, p.claimToken
	attrs := append(about(p.event, p.rule), "state", st.state, "reason", st.reason)
	if st.back != nil {
		attrs = append(attrs, "cause", st.back.Cause, "account", st.back.Account, "session_id", st.back.SessionID)
	}
	if r.reports == nil || r.bridgeID == "" {
		r.mu.Lock()
		r.dropClaimLocked(id, token)
		r.mu.Unlock()

		return
	}
	_, number := cardOf(p.event)
	r.reports.Enqueue(outbound.Report{
		Card: number,
		Rule: p.rule,
		Send: func(ctx context.Context) (bool, error) {
			var stored string
			var err error
			if st.back != nil {
				stored, err = r.workAPI.HandBackWorkRequest(ctx, r.bridgeID, id, token, *st.back)
			} else {
				stored, err = r.workAPI.SettleWorkRequest(ctx, r.bridgeID, id, token, st.state, st.reason)
			}
			var refusal *api.WorkRequestRefusal
			switch {
			case err == nil:
				r.log.Info("work_request_settled", append(attrs, "stored", stored)...)
			case errors.Is(err, api.ErrClaimLost):
				r.log.Warn("work_request_result_lost", append(attrs, "message", "the server no longer holds this claim for the bridge, so it dropped the result")...)
			case errors.As(err, &refusal) && refusal.RateLimited():
				return false, err
			case errors.As(err, &refusal), errors.Is(err, api.ErrWorkRequestNotFound), errors.Is(err, api.ErrWorkRequestsUnsupported):
				r.log.Warn("work_request_result_refused", append(attrs, "error", err.Error())...)
			default:
				return false, err
			}
			r.mu.Lock()
			r.dropClaimLocked(id, token)
			r.mu.Unlock()

			return true, nil
		},
		// The lease of a result that never lands runs out, and the server
		// offers the request again. Renewing it would hold the request forever.
		Lost: func(outbound.LossReason) {
			r.log.Warn("work_request_result_dropped", attrs...)
			r.mu.Lock()
			r.dropClaimLocked(id, token)
			r.mu.Unlock()
		},
	})
}
