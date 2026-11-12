package cmd

import (
	"context"
	"fmt"
	"net/url"
	"os"
	"os/signal"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/browser"
	"github.com/spf13/cobra"
)

// The timings of the install wait. A test replaces them.
var (
	installPollEvery = 3 * time.Second
	rulesReadWait    = 60 * time.Second
)

// initOpenBrowser opens a link. A test replaces it.
var initOpenBrowser = browser.Open

// siteLister reads the projects of the login.
type siteLister func(ctx context.Context) ([]api.Site, error)

// withInitValue adds the init value to an install link.
func withInitValue(link, sessionID string) string {
	sep := "?"
	if strings.Contains(link, "?") {
		sep = "&"
	}

	return link + sep + "init=" + url.QueryEscape(sessionID)
}

// waitsForApp says whether init offers to wait for the App.
func (c appCheck) waitsForApp() bool {
	return c.missing() && c.repository != ""
}

// initWaitForApp offers to install the App and waits until the repository is
// in the project. It returns the fresh project and true when the wait saw the
// install, and the old project and false otherwise.
func initWaitForApp(cmd *cobra.Command, list siteLister, run *initRun, site api.Site, app appCheck) (api.Site, bool) {
	out := cmd.OutOrStdout()
	if !app.waitsForApp() {
		return site, false
	}
	in := cmd.InOrStdin()
	if !confirm(out, in, "Install the Loupe GitHub App on "+app.repository+" now?") {
		return site, false
	}

	link := app.link
	if run.setWaiting(cmd.Context(), true) {
		link = withInitValue(link, run.sessionID)
		defer func() { run.setWaiting(context.Background(), false) }()
	}
	_ = initOpenBrowser(link)
	fmt.Fprintln(out, "Open this link to install the App: "+link)
	fmt.Fprintln(out, "Waiting for the install… Press Enter to stop waiting.")

	var sigs chan os.Signal
	if run.waitSigs != nil {
		sigs = run.waitSigs
		for len(sigs) > 0 {
			<-sigs
		}
	} else {
		sigs = make(chan os.Signal, 1)
		signal.Notify(sigs, os.Interrupt)
		defer signal.Stop(sigs)
	}
	run.beginWait()
	defer run.endWait()
	enter := watchEnter(in)
	defer enter.Stop()

	return waitForInstall(cmd.Context(), list, site, app.repository, enter.Pressed(), sigs)
}

// waitForInstall polls the project list until repository has the App, then
// until its rules are read, for at most rulesReadWait. A signal or Enter ends
// it.
func waitForInstall(ctx context.Context, list siteLister, site api.Site, repository string, enter <-chan struct{}, sigs <-chan os.Signal) (api.Site, bool) {
	tick := time.NewTicker(installPollEvery)
	defer tick.Stop()
	var installed *time.Time
	current := site
	for {
		select {
		case <-ctx.Done():
			return current, installed != nil
		case <-enter:
			return current, installed != nil
		case <-sigs:
			return current, installed != nil
		case <-tick.C:
		}
		if installed != nil && time.Since(*installed) >= rulesReadWait {
			return current, true
		}
		sites, err := list(ctx)
		if err != nil {
			continue
		}
		for _, s := range sites {
			if s.ID == site.ID {
				current = s
			}
		}
		repo, ok := listedRepository(current, repository)
		if !ok {
			continue
		}
		if installed == nil {
			now := time.Now()
			installed = &now
		}
		if repo.RulesRead != nil || time.Since(*installed) >= rulesReadWait {
			return current, true
		}
	}
}

// listedRepository finds repository among the repositories of site, when the
// App is installed for it.
func listedRepository(site api.Site, repository string) (api.GitHubRepository, bool) {
	if site.GitHub == nil {
		return api.GitHubRepository{}, false
	}
	for _, r := range site.GitHub.Repositories {
		if strings.EqualFold(r.Path, repository) && r.AppInstalled != nil && *r.AppInstalled {
			return r, true
		}
	}

	return api.GitHubRepository{}, false
}
