package cmd

import (
	"context"
	"maps"
	"reflect"
	"slices"
	"testing"

	harn "github.com/loupe-ac/loupe/cli/internal/harness"
)

func TestHealOnlyNeverTurnsOffAReadyPart(t *testing.T) {
	whole := harn.Problem{Reason: "login check timed out"}
	of := func(slug, reason string) harn.Problem { return harn.Problem{Project: slug, Reason: reason} }
	result := func(problems ...harn.Problem) []accountResult {
		return []accountResult{{name: "a", problems: problems}}
	}
	type projects = map[string]map[string]string
	for _, tc := range []struct {
		name            string
		off             map[string]string
		projectsOff     projects
		results         []accountResult
		wantOff         map[string]string
		wantProjectsOff projects
	}{
		{
			name: "a whole failure that passes",
			off:  map[string]string{"a": "not logged in"}, projectsOff: projects{"a": {"one": "old"}},
			results: result(), wantOff: map[string]string{}, wantProjectsOff: projects{},
		},
		{
			name: "a whole failure that now fails some projects takes the new ones",
			off:  map[string]string{"a": "not logged in"}, projectsOff: projects{"a": {"one": "old"}},
			results: result(of("two", "new")), wantOff: map[string]string{}, wantProjectsOff: projects{"a": {"two": "new"}},
		},
		{
			name: "a whole failure that stays takes the new reason and keeps the projects",
			off:  map[string]string{"a": "not logged in"}, projectsOff: projects{"a": {"one": "old"}},
			results: result(whole, of("two", "new")),
			wantOff: map[string]string{"a": "login check timed out"}, wantProjectsOff: projects{"a": {"one": "old"}},
		},
		{
			name: "a project failure keeps its state when the whole check fails",
			off:  map[string]string{}, projectsOff: projects{"a": {"one": "old"}},
			results: result(whole, of("one", "new")), wantOff: map[string]string{}, wantProjectsOff: projects{"a": {"one": "old"}},
		},
		{
			name: "a project failure heals, changes, and turns off no ready project",
			off:  map[string]string{}, projectsOff: projects{"a": {"one": "old", "two": "old"}},
			results: result(of("two", "new"), of("two", "more"), of("three", "new")),
			wantOff: map[string]string{}, wantProjectsOff: projects{"a": {"two": "new; more"}},
		},
		{
			name: "the last failed project heals",
			off:  map[string]string{}, projectsOff: projects{"a": {"one": "old"}},
			results: result(of("three", "new")), wantOff: map[string]string{}, wantProjectsOff: projects{},
		},
		{
			name:    "a ready account stays ready",
			results: result(whole, of("one", "new")), wantOff: map[string]string{}, wantProjectsOff: projects{},
		},
		{
			name: "an account with no result stays as it is",
			off:  map[string]string{"b": "not logged in"}, projectsOff: projects{"c": {"one": "old"}},
			results: result(), wantOff: map[string]string{"b": "not logged in"}, wantProjectsOff: projects{"c": {"one": "old"}},
		},
	} {
		t.Run(tc.name, func(t *testing.T) {
			off := maps.Clone(tc.off)
			var before projects
			if tc.projectsOff != nil {
				before = projects{}
				for k, v := range tc.projectsOff {
					before[k] = maps.Clone(v)
				}
			}

			gotOff, gotProjects := healOnly(tc.off, tc.projectsOff, tc.results)

			if !reflect.DeepEqual(gotOff, tc.wantOff) || !reflect.DeepEqual(gotProjects, tc.wantProjectsOff) {
				t.Fatalf("healOnly = %v, %v, want %v, %v", gotOff, gotProjects, tc.wantOff, tc.wantProjectsOff)
			}
			if !reflect.DeepEqual(tc.off, off) || !reflect.DeepEqual(tc.projectsOff, before) {
				t.Fatalf("healOnly changed its inputs: %v, %v", tc.off, tc.projectsOff)
			}
		})
	}
}

func TestCheckAccountsWithChecksOnlyTheNamedAccounts(t *testing.T) {
	loggedInClaude(t)
	set, _ := accountSet(t)
	checked := func(names []string) []string {
		var out []string
		for _, a := range checkAccountsWith(context.Background(), set, nil, names) {
			out = append(out, a.name)
		}

		return out
	}

	for _, tc := range []struct {
		names, want []string
	}{
		{nil, []string{"broken", "in", "out"}},
		{[]string{}, nil},
		{[]string{"out", "broken", "gone"}, []string{"broken", "out"}},
	} {
		if got := checked(tc.names); !slices.Equal(got, tc.want) {
			t.Errorf("checkAccountsWith(%q) checked %q, want %q", tc.names, got, tc.want)
		}
	}
}
