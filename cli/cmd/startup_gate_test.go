package cmd

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

type gateRun struct {
	err      error
	updated  int
	warnings []string
}

func runGate(g startupGate, cliRange string) gateRun {
	var out gateRun
	inner := g.updateNow
	g.updateNow = func(ctx context.Context, r string) error {
		out.updated++
		if inner == nil {
			return nil
		}

		return inner(ctx, r)
	}
	g.warn = func(msg string) { out.warnings = append(out.warnings, msg) }
	out.err = g.check(context.Background(), cliRange)

	return out
}

func TestTheStartupGateLetsAVersionInsideTheRangeRun(t *testing.T) {
	got := runGate(startupGate{version: "2.1.0"}, "^2.0")
	if got.err != nil || got.updated != 0 || len(got.warnings) != 0 {
		t.Fatalf("%+v", got)
	}
}

func TestTheStartupGateRunsWhenTheServerStatesNoRange(t *testing.T) {
	if got := runGate(startupGate{version: "1.0.0"}, ""); got.err != nil {
		t.Fatalf("%+v", got)
	}
}

func TestTheStartupGateWarnsAboutACustomBuildAndRuns(t *testing.T) {
	for _, version := range []string{"", "abc1234", "1.0.0"} {
		got := runGate(startupGate{version: version, custom: true, autoUpdate: true}, "^2.0")
		if got.err != nil || got.updated != 0 || len(got.warnings) != 1 || !strings.Contains(got.warnings[0], "^2.0") {
			t.Fatalf("version %q: %+v", version, got)
		}
	}
}

func TestTheStartupGateRefusesAReleaseBinaryWithTheFix(t *testing.T) {
	cases := map[string]struct {
		gate startupGate
		want string
	}{
		"auto-update off": {startupGate{version: "1.4.0"}, "Run: loupe update"},
		"shared setup":    {startupGate{version: "1.4.0", autoUpdate: true, shared: true}, "Run: LOUPE_CONFIG= loupe update"},
		"shared homebrew": {startupGate{version: "1.4.0", shared: true, install: installHomebrew}, "Run: brew upgrade loupe"},
		"homebrew":        {startupGate{version: "1.4.0", autoUpdate: true, install: installHomebrew}, "Run: brew upgrade loupe"},
		"too new":         {startupGate{version: "3.0.0", autoUpdate: true}, "Upgrade the server."},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			got := runGate(tc.gate, "^2.0")
			if got.err == nil || !strings.Contains(got.err.Error(), tc.want) || got.updated != 0 {
				t.Fatalf("%+v", got)
			}
		})
	}
}

func TestTheStartupGateUpdatesAReleaseBinaryWhenAutoUpdateIsOn(t *testing.T) {
	got := runGate(startupGate{version: "1.4.0", autoUpdate: true}, "^2.0")
	if got.updated != 1 || got.err == nil || !strings.Contains(got.err.Error(), "start the bridge again") {
		t.Fatalf("%+v", got)
	}
}

func TestTheStartupGateNamesWhyAnUpdateFailed(t *testing.T) {
	g := startupGate{version: "1.4.0", autoUpdate: true, updateNow: func(context.Context, string) error {
		return errors.New("open /opt/bin/.loupe-update-1: permission denied")
	}}
	got := runGate(g, "^2.0")
	if got.err == nil || !strings.Contains(got.err.Error(), "/opt/bin/") || !strings.Contains(got.err.Error(), "Run: loupe update") {
		t.Fatalf("%+v", got)
	}
}

func TestCheckStartupReadsTheRangeFromTheVersionsRoute(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/versions" {
			w.WriteHeader(http.StatusNotFound)

			return
		}
		_, _ = w.Write([]byte(`{"versions":[{"version":"v1","status":"beta"}],"cliRange":"^2.0"}`))
	}))
	t.Cleanup(srv.Close)
	client := api.New(srv.URL, "token", srv.Client())

	err := checkStartup(context.Background(), client, startupGate{version: "1.4.0"})
	if err == nil || !strings.Contains(err.Error(), "Run: loupe update") {
		t.Fatalf("outside: %v", err)
	}
	if err := checkStartup(context.Background(), client, startupGate{version: "2.0.1"}); err != nil {
		t.Fatalf("inside: %v", err)
	}
}

func TestCheckStartupRefusesAServerWithoutTheVersionsRoute(t *testing.T) {
	srv := httptest.NewServer(http.NotFoundHandler())
	t.Cleanup(srv.Close)

	err := checkStartup(context.Background(), api.New(srv.URL, "token", srv.Client()), startupGate{version: "2.0.0"})
	if !errors.Is(err, api.ErrServerTooOld) {
		t.Fatalf("err = %v", err)
	}
}
