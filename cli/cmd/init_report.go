package cmd

import (
	"cmp"
	"context"
	"errors"
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
	"github.com/loupe-ac/loupe/cli/internal/initview"
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
	// accountCheck says why the account check could not run, and is empty when
	// it ran.
	accountCheck string
	// profile says why the profile is not finished, and is empty when it is.
	profile string
	// restart is true when init installed the Claude Code plugins.
	restart bool
	// profileRan is true when the profile agent ran in this init.
	profileRan bool
	// discoveryStarted is true when init started discovery.
	discoveryStarted bool
}

// reportAccounts prints the state of each account of the rule file, in every
// project of the file, and returns the lines of the used accounts that fail for
// this project. A failure in another project, and an account that no
// effective work entry of this project uses, never count. It runs before the profile agent, which needs no account.
// sites is the project list of the server, which lacks a project that this run
// made. When the rule file cannot be read, it checks no account and returns
// the reason as unchecked.
func reportAccounts(ctx context.Context, out io.Writer, path string, site api.Site, sites []api.Site) (failing []string, unchecked string) {
	set, err := rules.Load(path, rules.Defaults{})
	if err != nil {
		fmt.Fprintln(out, initview.ShortPath(fmt.Sprintf("Accounts: failed, cannot read %s: %v.", path, err)))
		if errors.Is(err, rules.ErrMissing) {
			return nil, initview.ShortPath("no rule file at " + path)
		}

		return nil, initview.ShortPath("cannot read " + path + ": " + err.Error())
	}
	slug := siteRuleSlug(site)

	if !slices.ContainsFunc(sites, func(s api.Site) bool { return strings.EqualFold(s.ID, site.ID) }) {
		sites = append(slices.Clone(sites), site)
	}

	var rows [][]string
	var details []string
	for _, a := range initCheckAccounts(ctx, set, sites) {
		row, extra := accountRow(a, slug)
		rows = append(rows, row)
		details = append(details, extra...)
		if !slices.Contains(a.usedIn, slug) {
			continue
		}
		if reasons := nonEmpty(a.reason(), a.projectReasons()[slug]); len(reasons) > 0 {
			failing = append(failing, initview.ShortPath(a.name+": "+strings.Join(reasons, "; ")))
		}
	}
	for _, row := range rows {
		for i := range row {
			row[i] = initview.ShortPath(row[i])
		}
	}
	if len(rows) > 0 {
		fmt.Fprint(out, initview.Table(out, []string{"Account", "Agent", "State"}, rows))
	}
	for _, d := range details {
		fmt.Fprintf(out, "  %s\n", initview.ShortPath(d))
	}

	return failing, ""
}

// accountRow gives the table row of one account: its name, its agent, and its
// state. The state lists each problem of the whole account, then those of this
// project, then those of the other projects by slug. An account with no problem
// is ready. The second value holds the detail lines of the problems.
func accountRow(a accountResult, slug string) ([]string, []string) {
	label := a.harness
	if !a.used {
		label += ", unused"
	}
	if len(a.problems) == 0 {
		return []string{a.name, label, "ready"}, nil
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
	var states, details []string
	for _, p := range problems {
		state := "failing"
		switch rank(p) {
		case 1:
			state = "failing for this project"
		case 2:
			state = "failing for project " + p.Project
		}
		states = append(states, state+": "+p.Reason)
		if p.Detail != "" {
			details = append(details, a.name+": "+p.Detail)
		}
	}

	return []string{a.name, label, strings.Join(states, "; ")}, details
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
func reportAccountsStep(ctx context.Context, out io.Writer, path string, site api.Site, sites []api.Site) ([]string, string) {
	ctx, cancel := context.WithTimeout(ctx, accountCheckTimeout+10*time.Second)
	defer cancel()

	return reportAccounts(ctx, out, path, site, sites)
}

// filesNotPushed lists the files of the setup that the remote lacks. The files
// are .loupe.yaml, and the profile when the project needs one. A file that
// does not exist is the job of another step. A repository with no upstream
// counts as not pushed, because nothing can prove it. An ignored file looks
// clean to git status and git diff, so pushed also reads the upstream tree.
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
	if _, err := initGit(dir, "cat-file", "-e", ref+":./"+file); err != nil {
		return false
	}
	_, err = initGit(dir, "diff", "--quiet", ref, "--", file)

	return err == nil
}

// discoveryBlockers lists what stops discovery from starting: the open profile,
// the failing or unchecked accounts and the files that the remote lacks.
func discoveryBlockers(left initLeft, files []string) []string {
	var out []string
	if left.accountCheck != "" {
		out = append(out, "Check the accounts: "+left.accountCheck+". Then run `loupe init` again to check them.")
	}
	for _, a := range left.accounts {
		out = append(out, "Fix the account "+a+". Then run `loupe init` again to check it.")
	}
	if left.profile != "" {
		out = append(out, "Look for blockers: "+left.profile+". Run `loupe init` again to go on.")
	}
	if len(files) > 0 {
		out = append(out, "Commit and push "+strings.Join(files, " and ")+", because a worker sees only what you push.")
	}

	return out
}

// initNext prints the last lines. They name only what remains, and the link
// opens the project.
func initNext(out io.Writer, baseURL, dir string, site api.Site, left initLeft) {
	todo := discoveryBlockers(left, filesNotPushed(dir, site))
	blocked := len(todo) > 0
	if left.restart {
		todo = append(todo, "Restart Claude Code to load the Loupe plugins.")
	}
	if wantsDiscovery(site, left) && !left.discoveryStarted {
		if blocked {
			todo = append(todo, "Run `loupe init` again when these are done. It then offers to start discovery.")
		} else {
			todo = append(todo, "Start discovery with `loupe discovery start` when you are ready.")
		}
	}

	fmt.Fprintln(out)
	if len(todo) > 0 {
		fmt.Fprintln(out, "What remains:")
	}
	for i, t := range todo {
		fmt.Fprintf(out, "%d. %s\n", i+1, initview.ShortPath(t))
	}
	fmt.Fprintf(out, "Open %s/projects/%s\n", strings.TrimRight(baseURL, "/"), site.ID)
}

// wantsDiscovery says whether a discovery run is due: none ran yet, or the
// profile agent wrote a profile that the last report predates.
func wantsDiscovery(site api.Site, left initLeft) bool {
	return site.DiscoveryToStart() || left.profileRan
}
