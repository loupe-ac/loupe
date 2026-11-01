package rules

import (
	"slices"
	"strings"
	"testing"
)

func TestStopAtDefaultsTo95(t *testing.T) {
	s := parse(t, claudeAccount+claudeDefaults+oneWork)
	if got := s.StopAt("claude"); got != 95 {
		t.Fatalf("StopAt = %d", got)
	}
}

func TestStopAtTakesTheAccountValue(t *testing.T) {
	body := "accounts:\n  claude:\n    harness: claude-code\n    stopAt: 80\n  spare:\n    harness: claude-code\n    stopAt: 100\n" + claudeDefaults + oneWork
	s := parse(t, body)
	if s.StopAt("claude") != 80 || s.StopAt("spare") != 100 {
		t.Fatalf("StopAt = %d, %d", s.StopAt("claude"), s.StopAt("spare"))
	}
	if got := s.StopAt("missing"); got != DefaultStopAt {
		t.Fatalf("StopAt of an unknown account = %d", got)
	}
}

func TestParseRefusesAStopAtOutOfRange(t *testing.T) {
	for _, value := range []string{"0", "101", "-5"} {
		body := "accounts:\n  claude:\n    harness: claude-code\n    stopAt: " + value + "\n" + claudeDefaults + oneWork
		text, _ := file(t, body)
		_, err := Parse([]byte(text), Defaults{})
		want := "line 4: accounts.claude.stopAt " + value + " is not a whole number from 1 to 100"
		if err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("stopAt %s: err = %v, want %q", value, err, want)
		}
	}
}

func TestAccountKindsNamesTheKindsOfAnAccount(t *testing.T) {
	body := "accounts:\n  claude:\n    harness: claude-code\n  other:\n    harness: claude-code\n  idle:\n    harness: claude-code\n" + claudeDefaults +
		"projects:\n  loupe:\n    dir: {dir}\nwork:\n" +
		"  implement:\n    prompt: x\n    account: other\n" +
		"  fix:\n    prompt: x\n" +
		"  test:\n    action: command\n    run: [make, test]\n" +
		"  session:\n    action: interactive\n    prompt: x\n    account: other\n" +
		"experiments:\n  trial:\n    covers: [fix]\n    baseline: a\n    variants:\n      - {name: a, weight: 1}\n      - {name: b, weight: 1, account: idle}\n" +
		"launch:\n  command: [open, \"{script}\"]\n"
	s := parse(t, body)
	for account, want := range map[string][]string{
		"claude":  {"fix"},
		"other":   {"implement", "session"},
		"idle":    {"fix"},
		"missing": nil,
	} {
		if got := s.AccountKinds(account); !slices.Equal(got, want) {
			t.Errorf("AccountKinds(%s) = %v, want %v", account, got, want)
		}
	}
}

func TestAccountKindsKeepsTheSubjectOfAKind(t *testing.T) {
	body := claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nwork:\n  report:\n    prompt: x\n    subject: analysis\n"
	s := parse(t, body)
	if got := s.AccountKinds("claude"); !slices.Equal(got, []string{"analysis:report"}) {
		t.Errorf("AccountKinds = %v", got)
	}
}
