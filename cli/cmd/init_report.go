package cmd

import (
	"cmp"
	"context"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	harn "github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/profile"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// initCheckAccounts checks the accounts of the rule file against the projects of
// the server. A test replaces it.
var initCheckAccounts = checkAccountsWith

// initGit runs git in dir. A test replaces it.
var initGit = func(dir string, args ...string) (string, error) {
	c := exec.Command("git", args...)
	c.Dir = dir
	out, err := c.Output()

	return strings.TrimSpace(string(out)), err
}

// initLeft collects what remains after the steps of init, for the last lines.
type initLeft struct {
	// accounts holds one line for each used account that fails for the project.
	accounts []string
	// profile says why the profile is not finished, and is empty when it is.
	profile string
	// restart is true when init installed the Claude Code plugins.
	restart bool
}

// reportAccounts prints the state of each account of the rule file, in every
// project of the file, and returns the lines of the used accounts that fail for
// this project. A failure in another project, and an account that no rule uses,
// never count. It runs before the profile agent, which needs no account.
// sites is the project list of the server, which lacks a project that this run
// made.
func reportAccounts(ctx context.Context, out io.Writer, path string, site api.Site, sites []api.Site) []string {
	set, err := rules.Load(path, rules.Defaults{})
	if err != nil {
		fmt.Fprintf(out, "Accounts: failed, cannot read %s: %v.\n", path, err)

		return nil
	}
	slug := siteRuleSlug(site)

	if !slices.ContainsFunc(sites, func(s api.Site) bool { return strings.EqualFold(s.ID, site.ID) }) {
		sites = append(slices.Clone(sites), site)
	}

	var failing []string
	for _, a := range initCheckAccounts(ctx, set, sites) {
		printAccount(out, a, slug)
		if !a.used {
			continue
		}
		if reasons := nonEmpty(a.reason(), a.projectReasons()[slug]); len(reasons) > 0 {
			failing = append(failing, a.name+": "+strings.Join(reasons, "; "))
		}
	}

	return failing
}

// printAccount prints one line for each problem of the account: the problems of
// the whole account, then those of this project, then those of the other
// projects by slug. An account with no problem prints one ready line.
func printAccount(out io.Writer, a accountResult, slug string) {
	label := a.harness
	if !a.used {
		label += ", unused"
	}
	if len(a.problems) == 0 {
		fmt.Fprintf(out, "Account: %s (%s): ready\n", a.name, label)

		return
	}
	rank := func(p harn.Problem) int {
		switch p.Project {
		case "":
			return 0
		case slug:
			return 1
		}

		return 2
	}
	problems := slices.Clone(a.problems)
	slices.SortStableFunc(problems, func(x, y harn.Problem) int {
		return cmp.Or(cmp.Compare(rank(x), rank(y)), cmp.Compare(x.Project, y.Project))
	})
	for _, p := range problems {
		state := "failing"
		switch rank(p) {
		case 1:
			state = "failing for this project"
		case 2:
			state = "failing for project " + p.Project
		}
		fmt.Fprintf(out, "Account: %s (%s): %s: %s\n", a.name, label, state, p.Reason)
		if p.Detail != "" {
			fmt.Fprintf(out, "         %s\n", p.Detail)
		}
	}
}

func nonEmpty(values ...string) []string {
	var out []string
	for _, v := range values {
		if v != "" {
			out = append(out, v)
		}
	}

	return out
}

// reportAccountsStep runs the account check with the bound of the checks.
func reportAccountsStep(ctx context.Context, out io.Writer, path string, site api.Site, sites []api.Site) []string {
	ctx, cancel := context.WithTimeout(ctx, accountCheckTimeout+10*time.Second)
	defer cancel()

	return reportAccounts(ctx, out, path, site, sites)
}

// filesNotPushed lists the files of the setup that the remote lacks. The files
// are .loupe.yaml, and the profile when the project needs one. A file that
// does not exist is the job of another step. A repository with no upstream
// counts as not pushed, because nothing can prove it.
func filesNotPushed(dir string, site api.Site) []string {
	names := []string{projectfile.Name}
	if site.ProfileNeeded() {
		names = append(names, profile.Path)
	}
	var files []string
	for _, n := range names {
		if _, err := os.Stat(filepath.Join(dir, n)); err == nil {
			files = append(files, n)
		}
	}
	var open []string
	for _, f := range files {
		if !pushed(dir, f) {
			open = append(open, f)
		}
	}

	return open
}

func pushed(dir, file string) bool {
	if status, err := initGit(dir, "status", "--porcelain", "--", file); err != nil || status != "" {
		return false
	}
	ref, err := initGit(dir, "rev-parse", "--abbrev-ref", "@{upstream}")
	if err != nil || ref == "" {
		return false
	}
	_, err = initGit(dir, "diff", "--quiet", ref, "--", file)

	return err == nil
}

// initNext prints the last lines. They name only what remains, and the link
// opens the project.
func initNext(out io.Writer, baseURL, dir string, site api.Site, left initLeft) {
	var todo []string
	for _, a := range left.accounts {
		todo = append(todo, "Fix the account "+a+". Then run `loupe init` again to check it.")
	}
	if left.profile != "" {
		todo = append(todo, "Finish the profile: "+left.profile+". Run `loupe init` again to go on.")
	}
	if files := filesNotPushed(dir, site); len(files) > 0 {
		todo = append(todo, "Commit and push "+strings.Join(files, " and ")+", because a worker sees only what you push.")
	}
	if left.restart {
		todo = append(todo, "Restart Claude Code to load the Loupe plugins.")
	}
	if site.DiscoveryToStart() {
		todo = append(todo, "Start discovery with Run discovery on the project page, so an agent checks the profile against the repository.")
	}

	fmt.Fprintln(out)
	if len(todo) > 0 {
		fmt.Fprintln(out, "What remains:")
	}
	for i, t := range todo {
		fmt.Fprintf(out, "%d. %s\n", i+1, t)
	}
	fmt.Fprintf(out, "Open %s/projects/%s\n", strings.TrimRight(baseURL, "/"), site.ID)
}
