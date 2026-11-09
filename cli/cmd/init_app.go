package cmd

import (
	"errors"
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
	remote, err := initGit(dir, "remote", "get-url", "origin")
	onGitHub := false
	if err == nil && remote != "" {
		var perr error
		c.repository, perr = parseGitHubRemote(withoutUserinfo(remote))
		onGitHub = !errors.Is(perr, errNotGitHub)
		if perr != nil {
			c.repository = ""
		}
	}
	g := site.GitHub
	switch {
	case !onGitHub && (g == nil || len(g.Repositories) == 0):
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

	return c
}

// missing says whether the owner has an App step left.
func (c appCheck) missing() bool {
	return c.need == appInstall || c.need == appAddRepository
}

// headline is the sentence that tells the owner what to do, or "" when the App
// needs nothing.
func (c appCheck) headline() string {
	switch c.need {
	case appInstall:
		return "Install the Loupe GitHub App for this project. Without it, Loupe cannot see pull requests and checks, merge, or let agents push."
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
