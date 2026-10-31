package cmd

import (
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/usage"
)

const usageEvent = `{"type":"rate_limit_event","rate_limit_info":{"unifiedWindows":{"five_hour":{"utilization":0.29,"resetsAt":1793420400,"status":"allowed"},"seven_day":{"utilization":0.26,"resetsAt":1793912400,"status":"allowed"}}}}` + "\n"

func TestAHeartbeatCarriesTheReadingsOfAnAccountOnce(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stdout")
	if err := os.WriteFile(path, []byte(usageEvent), 0o600); err != nil {
		t.Fatal(err)
	}
	c := usage.NewCollector()
	c.Start("run", "claude", usage.NewTail(func() (string, bool) { return path, true }, usage.ParseClaude))

	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute, func(h *heartbeater) { h.usage = c })
	// The start heartbeat has no account rows, so the readings wait.
	hh.h.setBody(api.Heartbeat{Accounts: []api.AccountReport{{Name: "claude", State: api.AccountReady}, {Name: "other", State: api.AccountReady}}})
	eventually(t, "the heartbeat with account rows", func() bool { return client.count() >= 2 })
	hh.h.send()
	eventually(t, "the next heartbeat", func() bool { return client.count() >= 3 })

	client.mu.Lock()
	defer client.mu.Unlock()
	if got := client.sent[0].Accounts; got != nil {
		t.Fatalf("start heartbeat accounts = %+v", got)
	}
	rows := client.sent[1].Accounts
	if len(rows) != 2 || len(rows[0].Readings) != 2 || len(rows[1].Readings) != 0 {
		t.Fatalf("readings = %+v", rows)
	}
	if rows[0].Readings[0].Window != "session" || *rows[0].Readings[0].UsedPercent != 29 {
		t.Fatalf("session reading = %+v", rows[0].Readings[0])
	}
	for _, hb := range client.sent[2:] {
		if len(hb.Accounts[0].Readings) != 0 {
			t.Fatalf("a reading was sent twice: %+v", hb.Accounts)
		}
	}
}

func TestAFailedHeartbeatKeepsTheReadings(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stdout")
	if err := os.WriteFile(path, []byte(usageEvent), 0o600); err != nil {
		t.Fatal(err)
	}
	c := usage.NewCollector()
	c.Start("run", "claude", usage.NewTail(func() (string, bool) { return path, true }, usage.ParseClaude))

	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute, func(h *heartbeater) { h.usage = c })
	client.mu.Lock()
	client.errors = []error{os.ErrDeadlineExceeded}
	client.mu.Unlock()
	hh.h.setBody(api.Heartbeat{Accounts: []api.AccountReport{{Name: "claude", State: api.AccountReady}}})
	eventually(t, "the failed heartbeat", func() bool { return client.count() >= 2 })
	hh.h.send()
	eventually(t, "the next heartbeat", func() bool { return client.count() >= 3 })

	client.mu.Lock()
	defer client.mu.Unlock()
	if got := client.sent[2].Accounts[0].Readings; len(got) != 2 {
		t.Fatalf("readings after a failure = %+v", got)
	}
}

func TestAHeartbeatCarriesNoReadingWithoutAResetTime(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stdout")
	noReset := `{"type":"result","is_error":true,"result":"You've hit your session limit · resets soon"}` + "\n"
	if err := os.WriteFile(path, []byte(noReset), 0o600); err != nil {
		t.Fatal(err)
	}
	c := usage.NewCollector()
	c.Start("run", "claude", usage.NewTail(func() (string, bool) { return path, true }, usage.ParseClaude))

	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute, func(h *heartbeater) { h.usage = c })
	hh.h.setBody(api.Heartbeat{Accounts: []api.AccountReport{{Name: "claude", State: api.AccountReady}}})
	eventually(t, "the heartbeat with account rows", func() bool { return client.count() >= 2 })

	client.mu.Lock()
	defer client.mu.Unlock()
	for _, hb := range client.sent[1:] {
		for _, r := range hb.Accounts[0].Readings {
			if r.ResetsAt == nil {
				t.Fatalf("a reading with no reset time was sent: %+v", r)
			}
		}
	}
	if len(c.Pending()) != 0 {
		t.Fatalf("pending = %+v", c.Pending())
	}
}
