package cmd

import (
	"slices"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/event"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// tryNowCommand names the account and no run, as the server sends it.
func tryNowCommand(account string) api.Command {
	return api.Command{
		Type: event.CommandType, ProjectID: testProject, Subject: api.CommandSubject{Type: "bridge-command", ID: testCommandID},
		CommandID: testCommandID, Kind: api.CommandTryNow, BridgeID: testBridgeID, Account: account, ExpiresAt: soon(),
	}
}

// The command frees the account and answers done. A work request that the
// server offers after it is claimed.
func TestATryNowCommandFreesTheAccountAndTheWorkIsClaimed(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	acks := h.withAcks()
	h.worker.result = workerResult{hasResult: true, status: "finished"}
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)
	h.reply(api.HeartbeatReply{BlockedAccounts: blockedClaude(), WorkRequests: []api.WorkRequest{w}})
	if len(f.claimed()) != 0 {
		t.Fatal("the bridge claimed work of a blocked account")
	}

	h.reply(api.HeartbeatReply{Commands: []api.Command{tryNowCommand("claude")}})

	if h.router.blocked.has("claude") {
		t.Fatal("the account is still blocked")
	}
	if got := acks.recorded(); len(got) != 1 || got[0] != testBridgeID+" "+testCommandID+" done " {
		t.Fatalf("acks = %v", got)
	}
	h.offer(f, w)
	if got := f.claimed(); len(got) != 1 {
		t.Fatalf("claims = %v", got)
	}
}

// Another account stays blocked.
func TestATryNowCommandKeepsTheOtherAccountsBlocked(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	h.withAcks()

	h.reply(api.HeartbeatReply{BlockedAccounts: []api.BlockedAccount{{Account: "claude"}, {Account: "spare"}}})
	h.reply(api.HeartbeatReply{Commands: []api.Command{tryNowCommand("claude")}})

	if h.router.blocked.has("claude") || !h.router.blocked.has("spare") {
		t.Fatal("the command freed the wrong account")
	}
}

// The next reply holds the whole list. It blocks the account again when the
// server blocks it again.
func TestTheNextReplyStaysAuthoritativeAfterTryNow(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	h.withAcks()
	h.reply(api.HeartbeatReply{BlockedAccounts: blockedClaude()})
	h.reply(api.HeartbeatReply{Commands: []api.Command{tryNowCommand("claude")}})

	h.reply(api.HeartbeatReply{BlockedAccounts: blockedClaude()})

	if !h.router.blocked.has("claude") {
		t.Fatal("the reply did not block the account again")
	}
}

// The heartbeat goes out at once, so the server offers the waiting work.
func TestATryNowCommandSendsAHeartbeatAtOnce(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	h.withAcks()
	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Hour)
	h.router.heartbeat = hh.h

	h.reply(api.HeartbeatReply{Commands: []api.Command{tryNowCommand("claude")}})

	eventually(t, "the heartbeat of the command", func() bool { return hh.queue.recorded() == 2 })
}

// A command with no account is malformed, and the bridge drops it.
func TestATryNowCommandWithNoAccountIsDropped(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	acks := h.withAcks()
	h.reply(api.HeartbeatReply{BlockedAccounts: blockedClaude()})

	h.router.onHeartbeatReply(api.HeartbeatReply{Commands: []api.Command{tryNowCommand("")}})
	h.router.wg.Wait()

	if !h.router.blocked.has("claude") || len(acks.recorded()) != 0 {
		t.Fatalf("blocked = %v, acks = %v", h.router.blocked.has("claude"), acks.recorded())
	}
	if !slices.Contains(dropReasons(t, h), "malformed") {
		t.Fatalf("drop reasons = %v", dropReasons(t, h))
	}
}
