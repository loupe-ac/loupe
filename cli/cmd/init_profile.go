package cmd

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/harness/codex"
	"github.com/loupe-ac/loupe/cli/internal/initview"
	"github.com/loupe-ac/loupe/cli/internal/mcpjson"
	"github.com/loupe-ac/loupe/cli/internal/profile"
	"github.com/loupe-ac/loupe/cli/internal/skills"
	"github.com/spf13/cobra"
	"golang.org/x/term"
)

const (
	agentClaude = "claude"
	agentCodex  = "codex"

	// profilePackage holds the profile skill, and profileSkill is its name.
	profilePackage = "loupe-lifecycle"
	profileSkill   = "loupe-profile"
)

// initAgent runs an agent in the foreground, on the terminal of init. It needs
// the *os.File of the terminal as out: Go gives the child a pipe for any other
// writer, and Claude Code then runs in print mode. A test replaces it.
var initAgent = func(dir string, in io.Reader, out io.Writer, name string, args ...string) error {
	c := exec.Command(name, args...)
	c.Dir = dir
	c.Stdin, c.Stdout, c.Stderr = in, out, os.Stderr

	return c.Run()
}

// agentOutput is the output that an agent writes to: the one under the view of
// init, with the pending step lines drawn first.
func agentOutput(out io.Writer) io.Writer {
	if view, ok := out.(*initview.Writer); ok {
		return view.Unwrap()
	}

	return out
}

// isTerminalOutput reports whether an agent on out can draw for a person. Only
// a file that is a real terminal counts: Go gives the child a pipe for any
// other writer. A test replaces it.
var isTerminalOutput = fileIsTerminal

func fileIsTerminal(out io.Writer) bool {
	f, ok := out.(*os.File)

	return ok && term.IsTerminal(int(f.Fd()))
}

// initExecutable finds this binary. A test replaces it.
var initExecutable = os.Executable

// initProfile starts the agent that looks for blockers with the owner. It runs
// only for a project whose workflow needs a profile, and only when the file is
// missing or --review-profile asks for it. It never fails init: when it cannot
// start the agent, it prints the prompt to paste. It returns why the run is not
// finished, and an empty string when it is. It also says whether the agent ran,
// so a new discovery can replace an old report.
func initProfile(cmd *cobra.Command, dir string, site api.Site, o initOptions, app appCheck) (left string, agentRan bool) {
	out := cmd.OutOrStdout()

	if !site.ProfileNeeded() {
		stepSet(out, "Profile", "the workflow of this project needs no profile")

		return "", agentRan
	}
	status, err := profile.Check(dir)
	if err != nil {
		step(out, "Profile", "failed", err.Error())

		return "the check failed: " + err.Error(), agentRan
	}
	if !status.Missing && !o.reviewProfile {
		stepSet(out, "Profile", profile.Path+" exists. Pass --review-profile to look for blockers again")

		return "", agentRan
	}

	paths, packages := profilePackageDirs(site)
	skillPath := ""
	if dir := paths[profilePackage]; dir != "" {
		skillPath = filepath.Join(dir, "skills", profileSkill, "SKILL.md")
	}
	prompt := profilePrompt(site, skillPath, !status.Missing, app)

	agent, why := pickProfileAgent(cmd, o.agent, skillPath != "")
	if agent == "" {
		step(out, "Profile", "skipped", why+". Paste this prompt into an agent in this folder:")
		fmt.Fprintln(out)
		fmt.Fprintln(out, prompt)

		return "no agent ran: " + why, agentRan
	}

	if agent == agentCodex {
		if why := codexServerProblem(cmd, dir); why != "" {
			step(out, "Profile", "skipped", why+". Then run `loupe init` again, or paste this prompt into a Codex agent that has the Loupe tools:")
			fmt.Fprintln(out)
			fmt.Fprintln(out, prompt)

			return "no agent ran: " + why, agentRan
		}
	}

	args := []string{prompt}
	if agent == agentClaude {
		mcpConfig, err := profileMcpConfig()
		if err != nil {
			step(out, "Profile", "failed", "find loupe for the MCP server: "+err.Error()+". Paste this prompt into an agent in this folder:")
			fmt.Fprintln(out)
			fmt.Fprintln(out, prompt)

			return "no agent ran: cannot find loupe for the MCP server", agentRan
		}
		args = []string{}
		for _, p := range packages {
			args = append(args, "--plugin-dir", paths[p])
		}
		args = append(args, "--mcp-config", mcpConfig, "--", prompt)
	}
	step(out, "Profile", "started", "The "+agent+" agent looks for blockers with you")
	agentRan = true
	if err := initAgent(dir, cmd.InOrStdin(), agentOutput(out), agent, args...); err != nil {
		step(out, "Profile", "failed", agent+" stopped: "+err.Error()+". Paste this prompt into an agent in this folder:")
		fmt.Fprintln(out)
		fmt.Fprintln(out, prompt)

		return agent + " stopped: " + err.Error(), agentRan
	}

	if _, err := profile.Check(dir); err != nil {
		step(out, "Profile", "failed", err.Error())

		return "the check failed: " + err.Error(), agentRan
	}
	step(out, "Profile", "done", "the agent looked for blockers")

	return "", agentRan
}

// profileMcpConfig is the value of --mcp-config. It names the installed path of
// loupe, because the setup can declare no server, such as with --no-mcp.
func profileMcpConfig() (string, error) {
	path, err := initExecutable()
	if err != nil {
		return "", err
	}
	entry := map[string]any{"mcpServers": map[string]any{mcpjson.ServerKey: mcpjson.Entry{Command: path, Args: []string{"mcp"}}}}
	config, err := json.Marshal(entry)
	if err != nil {
		return "", err
	}

	return string(config), nil
}

// codexServerProblem says why a Codex agent started here has no Loupe tools, or
// returns an empty string when it has them. The profile skill calls
// `workflow_get` first, and Codex takes the server only from its own config.
func codexServerProblem(cmd *cobra.Command, dir string) string {
	ctx, cancel := context.WithTimeout(cmd.Context(), 2*time.Minute)
	defer cancel()

	t := codexTarget{}
	add := t.command("mcp", "add", mcpjson.ServerKey, "--", "loupe", "mcp")
	raw, err := t.run(ctx, dir, "mcp", "get", mcpjson.ServerKey, "--json")
	if err != nil {
		return "Codex reports no `" + mcpjson.ServerKey + "` MCP server. Run `" + add + "`"
	}
	decl, err := codex.ParseDeclaration(raw)
	switch {
	case err != nil:
		return "cannot read the `" + mcpjson.ServerKey + "` MCP server of Codex: " + err.Error()
	case !decl.StartsLoupeMcp():
		return "Codex runs the `" + mcpjson.ServerKey + "` MCP server as " + decl.Summary() + ", not `loupe mcp`. Change it to `loupe mcp`"
	case !decl.Enabled:
		return "the `" + mcpjson.ServerKey + "` MCP server of Codex is disabled. Set enabled = true under [mcp_servers." + mcpjson.ServerKey + "] in " + t.configFile()
	}
	if _, err := initLookPath(decl.Transport.Command); err != nil {
		return "Codex starts the `" + mcpjson.ServerKey + "` MCP server with `" + decl.Transport.Command + "`, which is not on your PATH"
	}

	return ""
}

// pickProfileAgent chooses the agent. It returns an empty name and the reason
// when init must print the prompt instead.
func pickProfileAgent(cmd *cobra.Command, flag string, haveSkill bool) (string, string) {
	if !haveSkill {
		return "", "this loupe holds no copy of the " + profilePackage + " skills that it can run. Run `loupe update`"
	}
	if flag != "" && flag != agentClaude && flag != agentCodex {
		return "", "--agent takes " + agentClaude + " or " + agentCodex
	}
	in := cmd.InOrStdin()
	if !isTerminal(in) || !isTerminalOutput(agentOutput(cmd.OutOrStdout())) {
		return "", "no terminal to run an agent on"
	}
	var found []string
	for _, name := range []string{agentClaude, agentCodex} {
		if _, err := initLookPath(name); err == nil {
			found = append(found, name)
		}
	}
	if flag != "" {
		if !slices.Contains(found, flag) {
			return "", flag + " is not on your PATH"
		}

		return flag, ""
	}
	switch {
	case len(found) == 0:
		return "", "no agent is on your PATH"
	case len(found) == 1:
		return found[0], ""
	}

	return askAgent(cmd.OutOrStdout(), in, found), ""
}

// askAgent asks once which of the agents writes the profile.
func askAgent(out io.Writer, in io.Reader, found []string) string {
	fmt.Fprintf(out, "Which agent writes the profile? [%s] (default %s): ", strings.Join(found, "/"), found[0])
	answer, _ := lineReader(in).ReadString('\n')
	answer = strings.ToLower(strings.TrimSpace(answer))
	if slices.Contains(found, answer) {
		return answer
	}

	return found[0]
}

// profilePrompt is the first message to the agent. It names the skill by a
// path too, because Codex has no way to load a package folder. It also carries
// the GitHub facts that the app knows, so the agent asks no question that a fact
// answers, and what the repository of this folder needs from the App.
func profilePrompt(site api.Site, skillPath string, fileExists bool, app appCheck) string {
	var b strings.Builder
	b.WriteString("Look for blockers in this repository with me. A blocker makes a worker fail on its first run, or does damage. ")
	b.WriteString("Use the " + profilePackage + ":" + profileSkill + " skill")
	if skillPath != "" {
		b.WriteString(", at " + skillPath)
	}
	b.WriteString(". The Loupe project is " + site.ID + ".")
	if fileExists {
		b.WriteString(" " + profile.Path + " exists. Leave it as it is.")
	}
	b.WriteString(" " + githubFacts(site))
	b.WriteString(" " + app.promptFact())

	return b.String()
}

// githubFacts says in one paragraph what the app knows of the GitHub
// repositories of the project. An app that sent none gives the unknown line.
func githubFacts(site api.Site) string {
	g := site.GitHub
	if g == nil {
		return "GitHub facts: unknown. The app sent none."
	}
	var b strings.Builder
	if g.AppInstalled {
		b.WriteString("GitHub facts: the Loupe GitHub App is installed for the project. Each repository says whether the App covers it.")
	} else {
		b.WriteString("GitHub facts: the Loupe GitHub App is not installed for the project.")
	}
	if len(g.Repositories) == 0 {
		b.WriteString(" The project claims no GitHub repository.")
	}
	for _, r := range g.Repositories {
		b.WriteString(" Repository " + r.Path + ":")
		switch {
		case r.AppInstalled == nil:
			b.WriteString(" App unknown,")
		case *r.AppInstalled:
			b.WriteString(" App installed,")
		default:
			b.WriteString(" App not installed,")
		}
		b.WriteString(" default branch " + factText(r.DefaultBranch) + ",")
		b.WriteString(" rulesRead " + factText(r.RulesRead) + ",")
		switch {
		case r.ReviewRequired == nil:
			b.WriteString(" reviewRequired unknown,")
		case *r.ReviewRequired:
			b.WriteString(" reviewRequired yes,")
		default:
			b.WriteString(" reviewRequired no,")
		}
		b.WriteString(" mergeApproval " + factText(r.MergeApproval))
		if r.MergeApprovalSource != "" {
			b.WriteString(" (source " + r.MergeApprovalSource + ")")
		}
		if r.MergeApprovalPullRequestURL != nil && *r.MergeApprovalPullRequestURL != "" {
			b.WriteString(", answered in the inbox, pull request " + *r.MergeApprovalPullRequestURL)
		}
		b.WriteString(".")
	}

	return b.String()
}

// factText is the value of an optional fact, or "unknown".
func factText(v *string) string {
	if v == nil || *v == "" {
		return "unknown"
	}

	return *v
}

// profilePackageDirs unpacks the starting copy into the store, and returns the
// current folder of each package that the project uses, with the package names
// in the order of use. It returns nothing when the store cannot serve the list,
// as the bridge starts no worker then.
func profilePackageDirs(site api.Site) (map[string]string, []string) {
	dir := config.Current().Dir
	_, _ = skills.Install(dir)

	picks, err := skills.Resolve(dir, version, site.PackagesIn(skills.Held(dir)))
	if err != nil {
		return nil, nil
	}
	dirs := map[string]string{}
	var used []string
	for _, pick := range picks {
		dirs[pick.Package] = pick.Dir
		used = append(used, pick.Package)
	}

	return dirs, used
}
