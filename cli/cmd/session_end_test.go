package cmd

import (
	"bytes"
	"context"
	"errors"
	"strings"
	"testing"
)

func runSessionEnd(t *testing.T, stdin string, closer func(context.Context, string) (int, error)) (string, error) {
	t.Helper()
	old := closeInteractiveSession
	closeInteractiveSession = closer
	t.Cleanup(func() { closeInteractiveSession = old })

	cmd := newSessionCmd()
	var out bytes.Buffer
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetIn(strings.NewReader(stdin))
	cmd.SetArgs([]string{"end"})
	err := cmd.Execute()

	return out.String(), err
}

func TestSessionEndClosesTheSessionOfTheHookInput(t *testing.T) {
	var got string
	out, err := runSessionEnd(t, `{"session_id":"abc-123","hook_event_name":"SessionEnd","reason":"logout"}`,
		func(_ context.Context, id string) (int, error) { got = id; return 1, nil })
	if err != nil {
		t.Fatal(err)
	}
	if got != "abc-123" || out != "" {
		t.Fatalf("id = %q, output = %q", got, out)
	}
}

func TestSessionEndRefusesInputWithoutASessionId(t *testing.T) {
	for _, in := range []string{``, `not json`, `{}`, `{"session_id":""}`, `{"session_id":5}`} {
		called := false
		_, err := runSessionEnd(t, in, func(context.Context, string) (int, error) { called = true; return 0, nil })
		if err == nil || called {
			t.Errorf("input %q: err = %v, called = %v", in, err, called)
		}
	}
}

func TestSessionEndReturnsTheCloseError(t *testing.T) {
	_, err := runSessionEnd(t, `{"session_id":"s"}`, func(context.Context, string) (int, error) { return 0, errors.New("boom") })
	if err == nil || !strings.Contains(err.Error(), "boom") {
		t.Fatalf("err = %v", err)
	}
}
