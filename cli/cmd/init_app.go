package cmd

import (
	"context"
	"errors"
	"net/url"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

// appNeed says what the repository of this folder needs from the Loupe GitHub
// App. An unclear state counts as a need, so init always gives the owner an
// action.
type appNeed int

const (
	// appCovered: the App is installed and delivers this repository.
	appCovered appNeed = iota
	// appOffGitHub: the origin remote is not on GitHub, so the App does not apply.
	appOffGitHub
	// appInstall: the project has no installation, or the app sent no facts.
	appInstall
	// appAddRepository: the project has an installation that does not deliver
	// this repository, or the app does not know whether it does.
	appAddRepository
	// appPickRepository: the App reaches this repository and no project holds
	// it, so the owner only has to pick it.
	appPickRepository
)

// appCheck is the App state of the repository of this folder.
type appCheck struct {
	need appNeed
	// repository is owner/name from the origin remote, or "" when unknown.
	repository string
	link       string
}

// checkApp compares the origin remote of dir with the GitHub facts of site.
func checkApp(baseURL, dir string, site api.Site) appCheck {
	c := appCheck{link: strings.TrimRight(baseURL, "/") + "/projects/" + site.ID + "/github/install"}
	repository, hasRemote, notGitHub := originRepository(dir)
	c.repository = repository
	g := site.GitHub
	switch {
	case notGitHub || (!hasRemote && (g == nil || len(g.Repositories) == 0)):
		c.need = appOffGitHub
	case g == nil || !g.AppInstalled:
		c.need = appInstall
	case c.repository == "":
		c.need = appAddRepository
	default:
		c.need = appAddRepository
		for _, r := range g.Repositories {
			if strings.EqualFold(r.Path, c.repository) && r.AppInstalled != nil && *r.AppInstalled {
				c.need = appCovered
			}
		}
	}
	if c.need == appAddRepository && c.repository != "" {
		c.link += "?repository=" + url.QueryEscape(c.repository)
	}

	return c
}

// repositoryLookup answers how a repository stands for a project, as
// Client.GitHubRepositoryStatus does.
type repositoryLookup func(ctx context.Context, handle, repository string) (string, error)

// refineApp asks the server whether the App already reaches the repository.
// A repository it reaches and no project holds becomes appPickRepository. An
// old server, a failed call or any other answer leaves the check as it was.
func refineApp(ctx context.Context, lookup repositoryLookup, site api.Site, c appCheck) appCheck {
	if c.need != appAddRepository || c.repository == "" {
		return c
	}
	if status, err := lookup(ctx, site.ID, c.repository); err == nil && status == "free" {
		c.need = appPickRepository
	}

	return c
}

// originRepository reads owner/name from the fetch and push addresses of
// origin. It returns "" when an address is not on GitHub or the addresses name
// different repositories, because git pushes to every push address. notGitHub is
// true only when every address is off GitHub.
func originRepository(dir string) (repository string, hasRemote, notGitHub bool) {
	var remotes []string
	for _, flags := range [][]string{{"--all"}, {"--push", "--all"}} {
		out, err := initGit(dir, append(append([]string{"remote", "get-url"}, flags...), "origin")...)
		if err != nil {
			return "", false, false
		}
		remotes = append(remotes, strings.Fields(out)...)
	}
	if len(remotes) == 0 {
		return "", false, false
	}
	offGitHub := 0
	for _, remote := range remotes {
		repo, err := parseGitHubRemote(withoutUserinfo(remote))
		switch {
		case errors.Is(err, errNotGitHub):
			offGitHub++
		case err != nil:
			return "", true, false
		case repository == "":
			repository = repo
		case !strings.EqualFold(repository, repo):
			return "", true, false
		}
	}
	if offGitHub == len(remotes) {
		return "", true, true
	}
	if offGitHub > 0 {
		return "", true, false
	}

	return repository, true, false
}

// missing says whether the owner has an App step left.
func (c appCheck) missing() bool {
	return c.need == appInstall || c.need == appAddRepository || c.need == appPickRepository
}

// headline is the sentence that tells the owner what to do, or "" when the App
// needs nothing.
func (c appCheck) headline() string {
	switch c.need {
	case appInstall:
		return "Install the Loupe GitHub App for this project. Without it, Loupe cannot see pull requests and checks, merge, or let agents push."
	case appPickRepository:
		return "Shared with Loupe, not in this project. Add " + c.repository + " to the project."
	case appAddRepository:
		if c.repository == "" {
			return "Make sure the Loupe GitHub App covers the repository of this folder. Loupe cannot tell whether it does."
		}

		return "The Loupe GitHub App does not cover " + c.repository + " yet. Add this repository to the App."
	}

	return ""
}

// promptFact is the App sentence of the profile prompt.
func (c appCheck) promptFact() string {
	switch c.need {
	case appCovered:
		return "This repository: " + c.repository + ". The Loupe GitHub App covers it."
	case appInstall:
		return "This repository: " + repoOrUnknown(c.repository) + ". The Loupe GitHub App is not installed for the project. Install link: " + c.link
	case appPickRepository:
		return "This repository: " + c.repository + ". The Loupe GitHub App reaches it, and no project holds it yet. Pick link: " + c.link
	case appAddRepository:
		return "This repository: " + repoOrUnknown(c.repository) + ". The Loupe GitHub App does not cover it, or Loupe cannot tell. Install link, which also adds a repository: " + c.link
	}

	return "This repository is not on GitHub, so the Loupe GitHub App does not apply."
}

func repoOrUnknown(repo string) string {
	if repo == "" {
		return "unknown"
	}

	return repo
}
