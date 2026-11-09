package cmd

import (
	"errors"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

func withOrigin(t *testing.T, remote string, err error) {
	t.Helper()
	old := initGit
	t.Cleanup(func() { initGit = old })
	initGit = func(_ string, args ...string) (string, error) {
		if strings.Join(args, " ") != "remote get-url origin" {
			t.Fatalf("unexpected git call: %v", args)
		}

		return remote, err
	}
}

func TestCheckAppComparesTheOriginWithTheProjectRepositories(t *testing.T) {
	no, yes := false, true
	installed := func(repos ...api.GitHubRepository) *api.GitHubFacts {
		return &api.GitHubFacts{AppInstalled: true, Repositories: repos}
	}
	for name, tc := range map[string]struct {
		remote string
		err    error
		github *api.GitHubFacts
		want   appNeed
	}{
		"no facts":                     {"https://github.com/o/r.git", nil, nil, appInstall},
		"not installed":                {"https://github.com/o/r.git", nil, &api.GitHubFacts{}, appInstall},
		"covered":                      {"https://github.com/o/r.git", nil, installed(api.GitHubRepository{Path: "o/r", AppInstalled: &yes}), appCovered},
		"covered, other case":          {"git@github.com:O/R.git", nil, installed(api.GitHubRepository{Path: "o/r", AppInstalled: &yes}), appCovered},
		"repository not listed":        {"https://github.com/o/r.git", nil, installed(api.GitHubRepository{Path: "o/other", AppInstalled: &yes}), appAddRepository},
		"repository not delivered":     {"https://github.com/o/r.git", nil, installed(api.GitHubRepository{Path: "o/r", AppInstalled: &no}), appAddRepository},
		"older app":                    {"https://github.com/o/r.git", nil, installed(api.GitHubRepository{Path: "o/r"}), appAddRepository},
		"off GitHub":                   {"https://gitlab.com/o/r.git", nil, nil, appOffGitHub},
		"no origin":                    {"", errors.New("exit 2"), nil, appOffGitHub},
		"no origin, project on GitHub": {"", errors.New("exit 2"), installed(api.GitHubRepository{Path: "o/r", AppInstalled: &yes}), appAddRepository},
	} {
		t.Run(name, func(t *testing.T) {
			withOrigin(t, tc.remote, tc.err)
			got := checkApp("https://app.test/", t.TempDir(), api.Site{ID: "p", GitHub: tc.github})
			if got.need != tc.want {
				t.Errorf("need = %v, want %v", got.need, tc.want)
			}
			if got.link != "https://app.test/projects/p/github/install" {
				t.Errorf("link = %q", got.link)
			}
			if got.missing() != (got.headline() != "") {
				t.Errorf("missing() = %v but headline = %q", got.missing(), got.headline())
			}
		})
	}
}

func TestInitNextPutsTheAppInstallFirst(t *testing.T) {
	remains := false
	var out strings.Builder
	app := appCheck{need: appInstall, repository: "o/r", link: "https://app.example/projects/p1/github/install"}

	initNext(&out, "https://app.example", t.TempDir(), api.Site{ID: "p1", DiscoveryRemains: &remains}, initLeft{app: app, restart: true})

	want := "\nLoupe GitHub App\n" +
		"  Install the Loupe GitHub App for this project. Without it, Loupe cannot see pull requests and checks, merge, or let agents push.\n" +
		"  https://app.example/projects/p1/github/install\n\n" +
		"What remains:\n" +
		"1. Install the Loupe GitHub App with the link above.\n" +
		"2. Restart Claude Code to load the Loupe plugins.\n" +
		"Open https://app.example/projects/p1\n"
	if got := out.String(); got != want {
		t.Errorf("output =\n%q\nwant\n%q", got, want)
	}
}

func TestInitNextSaysWhichRepositoryTheAppMisses(t *testing.T) {
	remains := false
	var out strings.Builder
	app := appCheck{need: appAddRepository, repository: "o/r", link: "https://app.example/projects/p1/github/install"}

	initNext(&out, "https://app.example", t.TempDir(), api.Site{ID: "p1", DiscoveryRemains: &remains}, initLeft{app: app})

	if !strings.Contains(out.String(), "The Loupe GitHub App does not cover o/r yet. Add this repository to the App.") {
		t.Errorf("output = %s", out.String())
	}
}
