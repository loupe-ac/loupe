package cmd

import (
	"bytes"
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/mcpjson"
	"github.com/ubermuda/loupe/cli/internal/profile"
	"github.com/ubermuda/loupe/cli/internal/projectfile"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/update"
)

const (
	pluginMarketplace = "ubermuda/loupe"
	pluginSpec        = "loupe@loupe"
	playwrightCommand = "npx playwright install chromium"

	codexSkillsRepository = "https://github.com/ubermuda/loupe"
)

// The programs init runs, and the checks it makes, are variables so a test
// replaces them.
var (
	initLookPath = exec.LookPath
	initGOOS     = runtime.GOOS
	initExec     = func(ctx context.Context, dir, name string, args ...string) ([]byte, error) {
		c := exec.CommandContext(ctx, name, args...)
		c.Dir = dir

		return c.CombinedOutput()
	}
	initStartBridge = startDaemon
	initBridgeRuns  = func(rulesPath string) bool {
		abs, err := filepath.Abs(rulesPath)
		if err != nil {
			return false
		}
		sock, err := socketPath(abs)

		return err == nil && controlAnswers(sock)
	}
	initReloadBridge = func(rulesPath string) error {
		abs, err := filepath.Abs(rulesPath)
		if err != nil {
			return err
		}
		sock, err := socketPath(abs)
		if err != nil {
			return err
		}
		res, err := requestReload(sock)
		if err != nil {
			return err
		}
		if !res.OK {
			problems := cmp.Or(strings.Join(res.Problems, "; "), "the bridge did not apply the rule file")
			if res.Stage != "" {
				problems = res.Stage + ": " + problems
			}

			return errors.New(problems)
		}

		return nil
	}
)

// initOptions are the flags of `loupe init`.
type initOptions struct {
	link      projectLinkOptions
	reset     bool
	rulesPath string
	baseURL   string
}

func newInitCmd() *cobra.Command {
	var o initOptions

	cmd := &cobra.Command{
		Use:   "init",
		Short: "Set up Loupe for this repository: sign in, link the project, write the rules and start the bridge",
		Long: "Runs the first-run steps in order. Each step skips itself when it is done already, " +
			"so you can run the command again and nothing changes.\n\n" +
			"1. Sign in with `loupe login`, when you are not signed in. --url names the instance. A login to another instance stops the command.\n" +
			"2. Choose the project. --project names it by its id or its slug. Else " + projectfile.Name + " in this directory " +
			"names it. Else the command lists your projects. When your login covers none, it " +
			"creates one from the `origin` remote of this repository.\n" +
			"3. Link this directory with `loupe project link`. That writes " + projectfile.Name + " and " +
			profile.Path + ", and checks the `" + mcpjson.ServerKey + "` MCP server. --force, --mcp, " +
			"--no-mcp and --mcp-json work as they do there. An existing " + projectfile.Name + " is kept.\n" +
			"4. Install the Loupe plugin in Claude Code, when `claude` is on your PATH and has no " +
			"Loupe plugin. When only `codex` is on your PATH, declare `loupe mcp` to Codex instead, " +
			"as --mcp and --no-mcp say, and print the approval line and the skills to add by hand. " +
			"When a command fails, the step prints it for you to run.\n" +
			"5. Write the rule file, rules.yaml in your config directory, or --rules. A new file gets " +
			"the first-run rules. An existing file gets only the keys it lacks, and its comments stay. " +
			"--reset writes the first-run rules again and keeps the projects map. A projects entry " +
			"that maps this project to another directory stops the command.\n" +
			"6. Ask whether to turn on automatic updates, when the rule file has no autoUpdate key. " +
			"The default is no, and a session with no terminal answers no.\n" +
			"7. Start the bridge in the background, as `loupe bridge run -d` does. A bridge that " +
			"runs already reloads the rule file when step 5 changed it.\n" +
			"8. Install the Playwright browser, when package.json lists Playwright and the browser " +
			"is missing. The command asks first.\n" +
			"9. Print the wizard page to open next.\n\n" +
			"Steps 1, 2, 3 and 5 stop the command when they fail. The other steps report a failure and go on. " +
			"A bridge that fails to start makes the command exit with an error after the last step.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			return runInit(cmd, o)
		},
	}
	cmd.Flags().StringVar(&o.link.projectID, "project", "", "project id or slug to use, instead of choosing from a list")
	cmd.Flags().BoolVar(&o.reset, "reset", false, "write the first-run rules again, and keep the projects map")
	cmd.Flags().BoolVar(&o.link.force, "force", false, "replace an existing "+projectfile.Name)
	cmd.Flags().BoolVar(&o.link.writeMcp, "mcp", false, "declare `loupe mcp` to Claude Code without asking")
	cmd.Flags().BoolVar(&o.link.skipMcp, "no-mcp", false, "leave the MCP server alone without asking")
	cmd.Flags().BoolVar(&o.link.writeMcpJSON, "mcp-json", false, "write "+mcpjson.Name+" instead, to commit the server to the repository")
	cmd.Flags().StringVar(&o.rulesPath, "rules", "", "read and write the rule file at this `path`; empty uses rules.yaml in your config directory")
	cmd.Flags().StringVar(&o.baseURL, "url", "", "Loupe base URL to sign in to (else LOUPE_URL env, else "+DefaultBaseURL+")")
	cmd.MarkFlagsMutuallyExclusive("mcp", "no-mcp")
	cmd.MarkFlagsMutuallyExclusive("mcp-json", "no-mcp")

	return cmd
}

// runInit runs the steps in order. A step that cannot go on returns its
// error. The bridge is the one step that reports its error after the last.
func runInit(cmd *cobra.Command, o initOptions) error {
	dir, err := os.Getwd()
	if err != nil {
		return err
	}
	cfg, err := initSignIn(cmd, o)
	if err != nil {
		return err
	}
	site, err := initProject(cmd, cfg, dir, o)
	if err != nil {
		return err
	}
	if err := initLink(cmd, dir, site.ID, o); err != nil {
		return err
	}
	initPlugin(cmd, dir)
	if codexAccount() {
		initCodex(cmd, dir, o.link)
	}

	path, err := rulesPathOr(o.rulesPath)
	if err != nil {
		return err
	}
	changed, err := initRules(cmd, path, dir, site, o.reset)
	if err != nil {
		return err
	}
	if initAutoUpdate(cmd, path) {
		changed = true
	}
	bridgeErr := initBridge(cmd, o.rulesPath, path, changed)
	initPlaywright(cmd, dir)
	initNext(cmd, cfg.BaseURL)

	return bridgeErr
}

// step prints the one line a step owes: its name, whether it ran, and why.
func step(out io.Writer, name, state, detail string) {
	fmt.Fprintf(out, "%s: %s, %s.\n", name, state, detail)
}

// initSignIn signs in when no login is stored.
func initSignIn(cmd *cobra.Command, o initOptions) (config.Config, error) {
	out := cmd.OutOrStdout()
	cfg, err := config.Load()
	if err == nil {
		// The machine holds one login, so a request for another instance
		// stops rather than replace the login a running bridge uses.
		if o.baseURL != "" || os.Getenv("LOUPE_URL") != "" {
			want := resolveBaseURL(o.baseURL, os.Getenv("LOUPE_URL"))
			if !strings.EqualFold(want, strings.TrimRight(cfg.BaseURL, "/")) {
				return config.Config{}, fmt.Errorf("you are signed in to %s, not %s: run `loupe login --url %s` to switch, then run `loupe init` again", cfg.BaseURL, want, want)
			}
		}
		step(out, "Sign in", "skipped", "you are signed in to "+cfg.BaseURL)

		return cfg, nil
	}
	if !errors.Is(err, config.ErrNotLoggedIn) {
		return config.Config{}, err
	}

	if err := deviceLogin(cmd, resolveBaseURL(o.baseURL, os.Getenv("LOUPE_URL"))); err != nil {
		return config.Config{}, err
	}
	step(out, "Sign in", "done", "you are signed in")

	return config.Load()
}

// initProject chooses the project of this repository. The returned site holds
// the slug when the server lists the project.
func initProject(cmd *cobra.Command, cfg config.Config, dir string, o initOptions) (api.Site, error) {
	out := cmd.OutOrStdout()
	ctx, cancel := context.WithTimeout(cmd.Context(), 30*time.Second)
	defer cancel()

	client := apiClient(cfg)
	sites, err := client.Sites(ctx)
	if err != nil {
		return api.Site{}, err
	}
	// find takes an id or a slug, because the first-run command names the
	// project by its slug. A handle that no listed project has stays as given.
	find := func(handle string) (api.Site, bool) {
		for _, s := range sites {
			if strings.EqualFold(s.ID, handle) || (s.Slug != "" && s.Slug == handle) {
				return s, true
			}
		}

		return api.Site{ID: handle}, false
	}

	// init keeps a project file without --force, so the file decides the
	// project, and a flag that names another one stops the command.
	linked := ""
	if _, err := os.Stat(filepath.Join(dir, projectfile.Name)); err == nil && !o.link.force {
		file, err := projectfile.Load(dir)
		if err != nil {
			return api.Site{}, fmt.Errorf("%w\n\nPass --force to replace the file", err)
		}
		if file.Project == "" {
			return api.Site{}, fmt.Errorf("%s names no project: pass --force to replace it", projectfile.Name)
		}
		linked = file.Project
	}

	if o.link.projectID != "" {
		site, ok := find(o.link.projectID)
		if !ok {
			return api.Site{}, fmt.Errorf("no project of yours has the id or slug %q", o.link.projectID)
		}
		if linked != "" && !strings.EqualFold(linked, site.ID) {
			linkedSite, _ := find(linked)
			return api.Site{}, fmt.Errorf("%s names %s, not %s: pass --force to replace the file, or leave out --project", projectfile.Name, describe(linkedSite), describe(site))
		}
		step(out, "Project", "done", "using "+describe(site))

		return site, nil
	}

	if linked != "" {
		site, ok := find(linked)
		if !ok {
			return api.Site{}, fmt.Errorf("%s names %s, which your login does not cover: sign in with the login that covers it, or pass --force to replace the file", projectfile.Name, linked)
		}
		step(out, "Project", "skipped", projectfile.Name+" names "+describe(site))

		return site, nil
	}

	if len(sites) == 0 {
		site, err := createProjectFromRemote(ctx, client, dir, cfg.BaseURL)
		if err != nil {
			return api.Site{}, err
		}
		step(out, "Project", "done", "created "+describe(site))

		return site, nil
	}

	id, err := chooseProject(out, cmd.InOrStdin(), sites)
	if err != nil {
		return api.Site{}, err
	}
	site, _ := find(id)
	step(out, "Project", "done", "using "+describe(site))

	return site, nil
}

// createProjectFromRemote creates the project of a login that covers none,
// and names it after the origin remote of the repository.
func createProjectFromRemote(ctx context.Context, client *api.Client, dir, baseURL string) (api.Site, error) {
	byHand := fmt.Sprintf("pass --project <id>, or create the project in the app at %s", baseURL)

	raw, err := initExec(ctx, dir, "git", "remote", "get-url", "origin")
	remote := withoutUserinfo(strings.TrimSpace(string(raw)))
	if err != nil || remote == "" {
		return api.Site{}, fmt.Errorf("your login covers no project, and this repository has no origin remote to name one: %s", byHand)
	}

	site, err := client.CreateProject(ctx, api.CreateProjectRequest{Remote: remote})
	if err != nil {
		return api.Site{}, fmt.Errorf("create a project from %s: %w\n\n%s", remote, err, byHand)
	}

	return site, nil
}

// withoutUserinfo drops the user and password of a URL remote, which can hold
// a token. An scp-style remote such as git@host:path has no password. A URL
// that url.Parse refuses, such as a password with a bare %, is cut by hand.
func withoutUserinfo(remote string) string {
	u, err := url.Parse(remote)
	if err != nil {
		scheme, rest, ok := strings.Cut(remote, "://")
		if !ok {
			return remote
		}
		authority, path, hasPath := strings.Cut(rest, "/")
		if at := strings.LastIndex(authority, "@"); at >= 0 {
			authority = authority[at+1:]
		}
		if !hasPath {
			return scheme + "://" + authority
		}

		return scheme + "://" + authority + "/" + path
	}
	if u.User == nil {
		return remote
	}
	u.User = nil

	return u.String()
}

// initLink writes the project file, the profile and the MCP declaration. A
// project file that exists is kept, and the profile and the MCP server still
// get their checks, because a clone of the repository has the file and lacks
// the declaration.
func initLink(cmd *cobra.Command, dir, projectID string, o initOptions) error {
	out := cmd.OutOrStdout()
	link := o.link
	// A Codex account gets its server from initCodex, and `claude mcp` is
	// missing, so the Claude Code declaration stays out of it.
	if codexAccount() && !link.writeMcpJSON {
		link.writeMcp, link.skipMcp = false, true
	}

	if _, err := os.Stat(filepath.Join(dir, projectfile.Name)); err == nil && !link.force {
		if err := writeProfile(cmd, dir); err != nil {
			return err
		}
		if err := offerServer(cmd, dir, link.writeMcp, link.skipMcp, link.writeMcpJSON); err != nil {
			return err
		}
		step(out, "Link", "skipped", projectfile.Name+" exists already")

		return nil
	}

	link.projectID = projectID
	if err := runProjectLink(cmd, link); err != nil {
		return err
	}
	step(out, "Link", "done", projectfile.Name+" names the project")

	return nil
}

// initPlugin installs the Loupe plugin in Claude Code. It never fails: what it
// cannot do, it prints for you to run.
func initPlugin(cmd *cobra.Command, dir string) {
	out := cmd.OutOrStdout()
	add := "claude plugin marketplace add " + pluginMarketplace
	install := "claude plugin install " + pluginSpec
	byHand := fmt.Sprintf("run `%s`, then `%s`", add, install)

	if _, err := initLookPath("claude"); err != nil {
		step(out, "Plugin", "skipped", "Claude Code is not on your PATH; to install the plugin later, "+byHand)

		return
	}
	ctx, cancel := context.WithTimeout(cmd.Context(), 2*time.Minute)
	defer cancel()

	if listed, err := initExec(ctx, dir, "claude", "plugin", "list"); err == nil && strings.Contains(string(listed), "loupe@") {
		step(out, "Plugin", "skipped", "Claude Code has the Loupe plugin")

		return
	}

	// A marketplace that is added already can make this command fail while the
	// install still works, so only the install decides.
	_, _ = initExec(ctx, dir, "claude", "plugin", "marketplace", "add", pluginMarketplace)
	if raw, err := initExec(ctx, dir, "claude", "plugin", "install", pluginSpec); err != nil {
		step(out, "Plugin", "failed", fmt.Sprintf("%v%s; %s", err, indented(raw), byHand))

		return
	}
	step(out, "Plugin", "done", "installed "+pluginSpec+". Restart Claude Code to load it")
}

// codexAccount reports whether the first-run rules give the bridge a Codex
// account.
func codexAccount() bool {
	return rules.FirstRunOptions{LookPath: initLookPath}.Harness() == rules.HarnessCodex
}

// initCodex connects Codex, when the rule file gives the bridge a Codex
// account. It declares `loupe mcp` as --mcp and --no-mcp say, and prints the
// approval line and the skills, which no codex command sets.
func initCodex(cmd *cobra.Command, dir string, link projectLinkOptions) {
	out := cmd.OutOrStdout()
	add := "codex mcp add " + mcpjson.ServerKey + " -- loupe mcp"
	ctx, cancel := context.WithTimeout(cmd.Context(), 30*time.Second)
	defer cancel()

	_, getErr := initExec(ctx, dir, "codex", "mcp", "get", mcpjson.ServerKey, "--json")
	switch {
	case getErr == nil:
		step(out, "Codex", "skipped", "Codex declares the `"+mcpjson.ServerKey+"` MCP server already")
	case link.skipMcp:
		step(out, "Codex", "skipped", "--no-mcp is set; to give Codex the Loupe tools, run `"+add+"`")
	case !link.writeMcp && !confirm(out, cmd.InOrStdin(), "Declare `loupe mcp` to Codex?"):
		step(out, "Codex", "skipped", "to give Codex the Loupe tools, run `"+add+"`")
	default:
		if raw, err := initExec(ctx, dir, "codex", "mcp", "add", mcpjson.ServerKey, "--", "loupe", "mcp"); err != nil {
			step(out, "Codex", "failed", fmt.Sprintf("%v%s; run `%s`", err, indented(raw), add))
		} else {
			step(out, "Codex", "done", "declared `loupe mcp`")
		}
	}

	fmt.Fprintf(out, "Codex needs two more steps by hand. Add `default_tools_approval_mode = \"approve\"` under [mcp_servers.%s] in ~/.codex/config.toml, "+
		"or the bridge turns its Codex account off. Copy the folders of plugins/loupe/skills/ from %s into ~/.agents/skills/, "+
		"so the workers have the stage skills.\n", mcpjson.ServerKey, codexSkillsRepository)
}

// indented formats the output of a failed command as a block after a message.
func indented(raw []byte) string {
	text := strings.TrimSpace(string(raw))
	if text == "" {
		return ""
	}
	if len(text) > maxStartError {
		text = text[len(text)-maxStartError:]
	}

	return ":\n  " + strings.ReplaceAll(text, "\n", "\n  ") + "\n"
}

// initRules writes the first-run rule file, or adds to the one that exists. It
// reports whether it changed a file that existed.
func initRules(cmd *cobra.Command, path, dir string, site api.Site, reset bool) (bool, error) {
	out := cmd.OutOrStdout()
	opts := rules.FirstRunOptions{Slug: cmp.Or(site.Slug, site.ID), ID: site.ID, Dir: dir, LookPath: initLookPath, GOOS: initGOOS}

	existing, err := os.ReadFile(path)
	missing := errors.Is(err, fs.ErrNotExist)
	if err != nil && !missing {
		return false, fmt.Errorf("read rule file: %w", err)
	}

	var (
		next   []byte
		detail string
	)
	switch {
	case missing:
		if next, err = rules.FirstRun(opts); err != nil {
			return false, err
		}
		detail = "wrote " + path
	case reset:
		if next, err = rules.Reset(existing, opts); err != nil {
			return false, fmt.Errorf("rule file %s: %w", path, err)
		}
		detail = "wrote the first-run rules to " + path + " and kept its projects map"
	default:
		var added []string
		if next, added, err = rules.Merge(existing, opts); err != nil {
			return false, fmt.Errorf("rule file %s: %w", path, err)
		}
		if len(added) == 0 {
			step(out, "Rules", "skipped", path+" has what the first run needs")
			noteNoProductDesign(out, path, next, opts)

			return false, nil
		}
		detail = "added " + strings.Join(added, ", ") + " to " + path
	}

	if !missing && bytes.Equal(existing, next) {
		step(out, "Rules", "skipped", path+" is the same already")
		noteNoProductDesign(out, path, next, opts)

		return false, nil
	}
	if err := saveRuleFile(path, next); err != nil {
		return false, err
	}
	step(out, "Rules", "done", detail)
	noteNoProductDesign(out, path, next, opts)

	return !missing, nil
}

// noteNoProductDesign says so when the rule file runs no interactive work
// because first-run knows no terminal on this machine.
func noteNoProductDesign(out io.Writer, path string, data []byte, opts rules.FirstRunOptions) {
	if opts.Terminal() != nil {
		return
	}
	set, err := rules.Parse(data, rules.Defaults{})
	if err != nil || set.HasInteractive() {
		return
	}
	step(out, "Product design", "skipped", fmt.Sprintf("this machine has no known terminal, so %s has no interactive entry and product design waits for a person. To run it here, add a `launch` command and a `work.%s` entry with `action: %s` to that file", path, rules.ProductDesignKind, rules.ActionInteractive))
}

// saveRuleFile replaces the file through a rename. A new file gets mode 0600
// in a config directory with mode 0700, and an old file keeps its mode. A
// symlink keeps pointing where it did.
func saveRuleFile(path string, data []byte) error {
	target, mode := path, os.FileMode(0o600)
	if real, err := filepath.EvalSymlinks(path); err == nil {
		target = real
		if info, err := os.Stat(real); err == nil {
			mode = info.Mode().Perm()
		}
	} else if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}

	return update.WriteFile(target, data, mode)
}

// initAutoUpdate asks about automatic updates when the rule file is silent on
// them, and reports whether it wrote the answer. It is a note on failure,
// because the file is written already.
func initAutoUpdate(cmd *cobra.Command, path string) bool {
	out := cmd.OutOrStdout()

	on, present, err := rules.ReadAutoUpdate(path)
	if err != nil {
		step(out, "Auto-update", "failed", err.Error())

		return false
	}
	if present {
		step(out, "Auto-update", "skipped", "the rule file sets autoUpdate to "+fmt.Sprint(on))

		return false
	}

	on = confirmNo(out, cmd.InOrStdin(), "Turn on automatic updates?")
	if _, err := rules.SetAutoUpdate(path, on); err != nil {
		step(out, "Auto-update", "failed", err.Error())

		return false
	}
	step(out, "Auto-update", "done", "automatic updates are "+onOff(on))
	if !on {
		fmt.Fprintln(out, "To turn them on later, run `loupe update auto on`.")
	}

	return true
}

// initBridge starts the bridge in the background, or reloads a running one
// when the rule file changed. It returns the failure instead of ending the
// command, so the later steps still run.
func initBridge(cmd *cobra.Command, rulesFlag, path string, changed bool) error {
	out := cmd.OutOrStdout()
	flag := ""
	if rulesFlag != "" {
		flag = " --rules " + rulesFlag
	}
	if initBridgeRuns(path) {
		if !changed {
			step(out, "Bridge", "skipped", "it runs already and reads "+path)

			return nil
		}
		if err := initReloadBridge(path); err != nil {
			step(out, "Bridge", "failed", fmt.Sprintf("it runs and did not reload %s: %v. After you fix that, run `loupe bridge reload%s`", path, err, flag))

			return fmt.Errorf("reload the bridge: %w", err)
		}
		step(out, "Bridge", "done", "it reloaded "+path)

		return nil
	}

	run := newBridgeRunCmd()
	run.SetOut(out)
	run.SetErr(cmd.ErrOrStderr())
	byHand := "loupe bridge run -d" + flag
	if rulesFlag != "" {
		if err := run.Flags().Set("rules", rulesFlag); err != nil {
			return err
		}
	}

	if err := initStartBridge(run, bridgeRunOptions{rulesPath: rulesFlag}); err != nil {
		step(out, "Bridge", "failed", fmt.Sprintf("%v. After you fix that, run `%s`", err, byHand))

		return fmt.Errorf("start the bridge: %w", err)
	}
	step(out, "Bridge", "done", "it runs in the background")

	return nil
}

// initPlaywright installs the Playwright browser when the repository uses
// Playwright and the browser is missing.
func initPlaywright(cmd *cobra.Command, dir string) {
	out := cmd.OutOrStdout()
	if !listsPlaywright(dir) {
		step(out, "Playwright", "skipped", "package.json does not list it")

		return
	}
	if playwrightBrowserInstalled(dir) {
		step(out, "Playwright", "skipped", "the Chromium browser is installed")

		return
	}
	if !confirm(out, cmd.InOrStdin(), "Install the Playwright browser?") {
		step(out, "Playwright", "skipped", "to install the browser later, run `"+playwrightCommand+"`")

		return
	}

	fmt.Fprintln(out, "Installing the browser. This can take a few minutes.")
	ctx, cancel := context.WithTimeout(cmd.Context(), 15*time.Minute)
	defer cancel()
	if raw, err := initExec(ctx, dir, "npx", "playwright", "install", "chromium"); err != nil {
		step(out, "Playwright", "failed", fmt.Sprintf("%v%s; run `%s`", err, indented(raw), playwrightCommand))

		return
	}
	step(out, "Playwright", "done", "the Chromium browser is installed")
}

// listsPlaywright reports whether package.json in dir depends on Playwright.
func listsPlaywright(dir string) bool {
	raw, err := os.ReadFile(filepath.Join(dir, "package.json"))
	if err != nil {
		return false
	}
	var pkg struct {
		Dependencies    map[string]any `json:"dependencies"`
		DevDependencies map[string]any `json:"devDependencies"`
	}
	if json.Unmarshal(raw, &pkg) != nil {
		return false
	}
	for _, deps := range []map[string]any{pkg.Dependencies, pkg.DevDependencies} {
		for _, name := range []string{"@playwright/test", "playwright"} {
			if _, ok := deps[name]; ok {
				return true
			}
		}
	}

	return false
}

// playwrightBrowserInstalled looks for a Chromium folder in the browser cache
// of Playwright.
func playwrightBrowserInstalled(dir string) bool {
	cache := os.Getenv("PLAYWRIGHT_BROWSERS_PATH")
	switch {
	case cache == "0":
		cache = filepath.Join(dir, "node_modules", "playwright-core", ".local-browsers")
	case cache != "":
	default:
		home, err := os.UserHomeDir()
		if err != nil {
			return false
		}
		cache = filepath.Join(home, ".cache", "ms-playwright")
		if runtime.GOOS == "darwin" {
			cache = filepath.Join(home, "Library", "Caches", "ms-playwright")
		}
	}

	entries, err := os.ReadDir(cache)
	if err != nil {
		return false
	}
	for _, e := range entries {
		if e.IsDir() && strings.HasPrefix(e.Name(), "chromium") {
			return true
		}
	}

	return false
}

// initNext sends you to the machine step of the wizard, which shows the local
// steps and leads on to the Workshop.
func initNext(cmd *cobra.Command, baseURL string) {
	out := cmd.OutOrStdout()

	fmt.Fprintln(out)
	fmt.Fprintf(out, "Open %s/welcome/connect to finish the setup.\n", baseURL)
	fmt.Fprintf(out, "Commit %s, because a worker sees only what you push.\n", profile.Path)
}
