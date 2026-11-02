package cmd

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"text/tabwriter"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/claudecode"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/mcpjson"
	"github.com/loupe-ac/loupe/cli/internal/profile"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
	"github.com/spf13/cobra"
	"golang.org/x/term"
)

// projectLinkOptions are the flags of `loupe project link`.
type projectLinkOptions struct {
	projectID    string
	force        bool
	writeMcp     bool
	skipMcp      bool
	writeMcpJSON bool
}

func newProjectCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "project",
		Short: "Work with the Loupe project of this repository",
	}
	cmd.AddCommand(newProjectLinkCmd(), newProjectListCmd())

	return cmd
}

func newProjectLinkCmd() *cobra.Command {
	var o projectLinkOptions

	cmd := &cobra.Command{
		Use:   "link",
		Short: "Write " + projectfile.Name + ", the file that names this repository's project",
		Long: "Writes " + projectfile.Name + " in the current directory. `loupe mcp` reads it and " +
			"sends that project with every request, so an agent in this repository acts on this " +
			"project and no other.\n\n" +
			"With no --project, it lists the projects your login covers and asks which one. " +
			"It asks also when your login covers one project. With no terminal to answer, it " +
			"prints the list and stops, so pass --project <slug>.\n\n" +
			"It refuses to replace an existing file unless you pass --force.\n\n" +
			"It also writes " + profile.Path + " when the repository has none, also when " +
			projectfile.Name + " exists already. The stage skills " +
			"read that profile, and stop without it. The starter guesses the gate commands from " +
			"the files it finds, marks each guess \"" + profile.Marker + "\", and marks each value it " +
			"cannot know \"" + profile.OpenQuestion + "\". Commit the file " +
			"after you read it. A profile that exists is never replaced.\n\n" +
			"It then checks how Claude Code starts the `" + mcpjson.ServerKey + "` MCP server, and " +
			"offers to declare `loupe mcp` for every project when nothing does. One declaration " +
			"serves every repository, because the working directory chooses the project.\n\n" +
			"Use --mcp or --no-mcp to answer without being asked. --mcp declares a missing server " +
			"and never replaces one that exists, so a script cannot drop a declaration you made " +
			"by hand.\n\n" +
			"Pass --mcp-json to write " + mcpjson.Name + " instead, which commits the server to the " +
			"repository for everyone who clones it. Claude Code prefers its own declaration over " +
			"that file, and asks you to approve the file once.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			return runProjectLink(cmd, o)
		},
	}
	cmd.Flags().StringVar(&o.projectID, "project", "", "project id to write, instead of choosing from a list")
	cmd.Flags().BoolVar(&o.force, "force", false, "replace an existing "+projectfile.Name)
	cmd.Flags().BoolVar(&o.writeMcp, "mcp", false, "declare `loupe mcp` to Claude Code without asking")
	cmd.Flags().BoolVar(&o.skipMcp, "no-mcp", false, "leave the MCP server alone without asking")
	cmd.Flags().BoolVar(&o.writeMcpJSON, "mcp-json", false, "write "+mcpjson.Name+" instead, to commit the server to the repository")
	cmd.MarkFlagsMutuallyExclusive("mcp", "no-mcp")
	cmd.MarkFlagsMutuallyExclusive("mcp-json", "no-mcp")

	return cmd
}

func newProjectListCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "list",
		Short: "List the projects your login covers",
		Long: "Prints the id, the slug and the name of each project your login covers. " +
			"Pass the id to `loupe init --project <id>` or `loupe project link --project <id>`.\n\n" +
			"A star marks the project of the current directory. In a setup other than the default, " +
			"the project link of the setup names it first, then " + projectfile.Name + ". " +
			"In the default setup, " + projectfile.Name + " names it.",
		Args: cobra.NoArgs,
		RunE: runProjectList,
	}
}

func runProjectList(cmd *cobra.Command, _ []string) error {
	out := cmd.OutOrStdout()
	ctx, cancel := context.WithTimeout(cmd.Context(), 30*time.Second)
	defer cancel()
	sites, err := loginSites(ctx)
	if err != nil {
		return err
	}
	if len(sites) == 0 {
		fmt.Fprintln(out, "Your login covers no project. Create one with `loupe init --name \"<name>\"`, or in the app.")

		return nil
	}

	current, source := listedProject(cmd.ErrOrStderr())
	marked := false
	w := tabwriter.NewWriter(out, 0, 0, 2, ' ', 0)
	fmt.Fprintln(w, "  ID\tSLUG\tNAME")
	for _, s := range sites {
		mark := " "
		if current != "" && strings.EqualFold(s.ID, current) {
			mark, marked = "*", true
		}
		slug := s.Slug
		if slug == "" {
			slug = "-"
		}
		fmt.Fprintf(w, "%s %s\t%s\t%s\n", mark, s.ID, slug, s.Name)
	}
	if err := w.Flush(); err != nil {
		return err
	}
	switch {
	case marked:
		fmt.Fprintf(out, "\n* marks the project that %s names.\n", source)
	case current != "":
		fmt.Fprintf(out, "\n%s names project %s, which your login does not cover.\n", source, current)
	}

	return nil
}

// listedProject gives the project of the working directory and the words
// that name where it came from, in the order `loupe mcp` reads them. A file
// that fails to read gives no project and a note on notes, because the list
// helps to fix it. A broken link file never falls back to the project file,
// because `loupe mcp` does not fall back either.
func listedProject(notes io.Writer) (project, source string) {
	dir, err := os.Getwd()
	if err != nil {
		fmt.Fprintln(notes, err)

		return "", ""
	}
	link, err := setupLink(dir)
	if err != nil {
		fmt.Fprintf(notes, "read the project links of the setup %s: %v\n", config.Current().Dir, err)

		return "", ""
	}
	if link != "" {
		return link, "the project link of the setup " + config.Current().Dir
	}
	file, err := projectfile.Load(dir)
	if err != nil {
		if !errors.Is(err, projectfile.ErrNoFile) {
			fmt.Fprintln(notes, err)
		}

		return "", ""
	}

	return file.Project, projectfile.Name
}

// loginSites lists the projects that the stored login covers.
func loginSites(ctx context.Context) ([]api.Site, error) {
	cfg, err := config.Load()
	if err != nil {
		return nil, err
	}

	return apiClient(cfg).Sites(ctx)
}

// runProjectLink links the current directory to a project. It reads and
// writes through the streams and the context of cmd.
func runProjectLink(cmd *cobra.Command, o projectLinkOptions) error {
	dir, err := os.Getwd()
	if err != nil {
		return err
	}

	if inSecondSetup() {
		return runSetupProjectLink(cmd, dir, o)
	}

	// Whether the file parses is beside the point. Replacing one
	// somebody wrote is the thing that needs saying yes to.
	if _, err := os.Stat(filepath.Join(dir, projectfile.Name)); err == nil && !o.force {
		// A repository linked before the profile existed still needs one.
		if err := writeProfile(cmd, dir); err != nil {
			return err
		}
		if !o.writeMcp && !o.writeMcpJSON {
			return fmt.Errorf("%s already exists: pass --force to replace it", projectfile.Name)
		}

		// --mcp on a repository that already names its project asks for
		// the second half alone. Refusing here would make the message
		// printed when somebody declines the offer a dead end.
		fmt.Fprintf(cmd.OutOrStdout(), "%s already exists, so it is kept.\n", projectfile.Name)

		return offerServer(cmd, dir, o.writeMcp, o.skipMcp, o.writeMcpJSON)
	}

	projectID := o.projectID
	if projectID == "" {
		cfg, err := config.Load()
		if err != nil {
			return err
		}

		ctx, cancel := context.WithTimeout(cmd.Context(), 10*time.Second)
		defer cancel()
		sites, err := apiClient(cfg).Sites(ctx)
		if err != nil {
			return err
		}

		choice, err := chooseProject(cmd.OutOrStdout(), cmd.InOrStdin(), sites, false)
		if err != nil {
			return err
		}
		projectID = choice.id
	}

	if err := projectfile.Write(dir, projectID); err != nil {
		return err
	}
	fmt.Fprintf(cmd.OutOrStdout(), "Wrote %s for project %s.\n", projectfile.Name, projectID)

	if err := writeProfile(cmd, dir); err != nil {
		return err
	}

	return offerServer(cmd, dir, o.writeMcp, o.skipMcp, o.writeMcpJSON)
}

// runSetupProjectLink links the folder in the setup. It writes
// projects.json in the setup folder and no file of the repository.
func runSetupProjectLink(cmd *cobra.Command, dir string, o projectLinkOptions) error {
	setup := config.Current()
	key := projectfile.LinkKey(dir)
	current, err := projectfile.LoadLink(setup.Dir, key)
	if err != nil {
		return err
	}
	if current != "" && !o.force {
		return fmt.Errorf("the setup %s links this folder to project %s already: pass --force to replace the link", setup.Dir, current)
	}

	projectID := o.projectID
	if projectID == "" {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		ctx, cancel := context.WithTimeout(cmd.Context(), 10*time.Second)
		defer cancel()
		sites, err := apiClient(cfg).Sites(ctx)
		if err != nil {
			return err
		}
		choice, err := chooseProject(cmd.OutOrStdout(), cmd.InOrStdin(), sites, false)
		if err != nil {
			return err
		}
		projectID = choice.id
	}
	if err := projectfile.WriteLink(setup.Dir, key, projectID); err != nil {
		return err
	}
	fmt.Fprintf(cmd.OutOrStdout(), "The setup %s links this folder to project %s. No file of the repository changed.\n", setup.Dir, projectID)

	return nil
}

// writeProfile gives the repository its starter profile. The file is a guess
// that only a commit makes real, so the message says so.
func writeProfile(cmd *cobra.Command, dir string) error {
	out := cmd.OutOrStdout()

	result, err := profile.Write(dir)
	if err != nil {
		return fmt.Errorf("write %s: %w", profile.Path, err)
	}
	if profile.Existing == result {
		fmt.Fprintf(out, "%s already exists, so it is kept.\n", profile.Path)

		return nil
	}

	fmt.Fprintf(out, "Wrote %s. Each guess is marked %q. Read it and commit it, because a worker sees only what you push.\n", profile.Path, profile.Marker)

	return nil
}

// offerServer makes sure something starts `loupe mcp` for this repository.
// Writing .loupe.yaml alone changes nothing an agent can see, and leaving this
// out is why somebody installs the CLI and finds their agent unchanged.
func offerServer(cmd *cobra.Command, dir string, write, skip, useFile bool) error {
	if skip {
		return nil
	}
	if useFile {
		return writeRepositoryFile(cmd, dir, write)
	}

	out := cmd.OutOrStdout()

	// Three scopes can declare the server, and a higher one hides a lower. So
	// one pass can fix a layer and uncover another. The bound matters more than
	// the count: `claude mcp` reports success for a call that changed nothing,
	// so a loop that trusts it must still end.
	for range 3 {
		got, err := claudecode.Effective(dir, mcpjson.ServerKey, "")
		if err != nil {
			// Claude Code owns its configuration. Failing to read it says
			// nothing about whether loupe project link did its job, so it is a note.
			fmt.Fprintf(out, "Could not check how Claude Code starts %q: %v\n", mcpjson.ServerKey, err)

			return nil
		}

		if got.Correct() {
			fmt.Fprintf(out, "Claude Code already starts `loupe mcp` for %s. Nothing to change.\n", got.Where())

			return nil
		}
		if !got.Declared() {
			return declareServer(cmd, dir, write)
		}
		if !replaceServer(cmd, dir, got) {
			return nil
		}
	}

	return nil
}

// declareServer offers the missing declaration. It asks unless --mcp already
// answered, because a script that passed no flag must change nothing.
func declareServer(cmd *cobra.Command, dir string, write bool) error {
	out := cmd.OutOrStdout()
	if !write {
		fmt.Fprintf(out, "\nNothing starts the %q MCP server, so your agent has no Loupe tools.\n", mcpjson.ServerKey)
		fmt.Fprintln(out, "One declaration covers every project, because the working directory chooses the project.")
		if !confirm(out, cmd.InOrStdin(), "Declare `loupe mcp` to Claude Code?") {
			fmt.Fprintf(out, "Left it. Run `%s` when you want the tools.\n", claudecode.AddCommand(mcpjson.ServerKey, claudecode.ScopeUser))

			return nil
		}
	}

	return apply(cmd, func(ctx context.Context) error {
		return claudecode.Add(ctx, dir, mcpjson.ServerKey, claudecode.ScopeUser)
	}, "Declared `loupe mcp`. Restart your agent to pick it up.", claudecode.AddCommand(mcpjson.ServerKey, claudecode.ScopeUser))
}

// replaceServer offers to drop a declaration that starts something else, and
// reports whether it went, so the caller knows to look for another behind it.
//
// Replacing always asks, whatever the flags say. A script must not remove a
// declaration somebody made by hand.
func replaceServer(cmd *cobra.Command, dir string, got claudecode.Resolution) bool {
	out := cmd.OutOrStdout()
	remove := claudecode.RemoveCommand(mcpjson.ServerKey, got.Scope)

	fmt.Fprintf(out, "\nClaude Code starts %q from %s, as %s.\n", mcpjson.ServerKey, got.Where(), got.Entry.Summary())
	fmt.Fprintln(out, "That is not `loupe mcp`, so this repository's project file does not reach it.")
	if claudecode.ScopeUser == got.Scope {
		fmt.Fprintln(out, "It covers every project, so removing it affects your other repositories too.")
	}

	if !confirm(out, cmd.InOrStdin(), "Remove it?") {
		fmt.Fprintf(out, "Left it. Run `%s` to remove it yourself.\n", remove)

		return false
	}

	if err := apply(cmd, func(ctx context.Context) error {
		return claudecode.Remove(ctx, dir, mcpjson.ServerKey, got.Scope)
	}, fmt.Sprintf("Removed the %s declaration.", got.Scope), remove); err != nil {
		return false
	}

	return true
}

// writeRepositoryFile puts the server in .mcp.json, which commits it to the
// repository. Claude Code prefers its own declaration, so the file can be
// correct and inert, and offerServer reports that on the next run.
func writeRepositoryFile(cmd *cobra.Command, dir string, write bool) error {
	out := cmd.OutOrStdout()

	state, _, err := mcpjson.Read(dir)
	if err != nil {
		return err
	}
	if mcpjson.Current == state {
		fmt.Fprintf(out, "%s already starts `loupe mcp`.\n", mcpjson.Name)

		return nil
	}
	if !write && !confirm(out, cmd.InOrStdin(), fmt.Sprintf("Write `loupe mcp` into %s?", mcpjson.Name)) {
		fmt.Fprintf(out, "Left %s alone.\n", mcpjson.Name)

		return nil
	}

	if err := mcpjson.Write(dir); err != nil {
		return err
	}
	// A .mcp.json names programs to run and travels with the repository, so an
	// agent gates it behind an approval the first time rather than on trust.
	fmt.Fprintf(out, "Wrote %s. Restart your agent, which asks you to approve the server once.\n", mcpjson.Name)

	higher, err := claudecode.Effective(dir, mcpjson.ServerKey, "")
	if err == nil && higher.Declared() && claudecode.ScopeProject != higher.Scope {
		fmt.Fprintf(out, "Claude Code starts %q from %s instead, so the file has no effect yet.\n", mcpjson.ServerKey, higher.Where())
		fmt.Fprintf(out, "Run `%s` to let the file win.\n", claudecode.RemoveCommand(mcpjson.ServerKey, higher.Scope))
	}

	return nil
}

// apply runs one change against Claude Code and reports what happened. A
// failure names the command to run by hand, and is never fatal: the project
// file is written either way.
func apply(cmd *cobra.Command, change func(context.Context) error, done, byHand string) error {
	out := cmd.OutOrStdout()
	ctx, cancel := context.WithTimeout(cmd.Context(), 30*time.Second)
	defer cancel()

	if err := change(ctx); err != nil {
		fmt.Fprintf(out, "Could not do it: %v\n", err)
		fmt.Fprintf(out, "Run `%s` by hand.\n", byHand)

		return err
	}
	fmt.Fprintln(out, done)

	return nil
}

// confirm asks a yes or no question. Enter means yes, and every answer that is
// not an answer means no: a stdin that is not a terminal, a closed stdin, or a
// read that fails. A script that passed no flag therefore leaves the file alone
// rather than hanging on a prompt nobody can see.
func confirm(out io.Writer, in io.Reader, question string) bool {
	return askYesNo(out, in, question, true)
}

// confirmNo is confirm with Enter meaning no, for a change that nobody
// expects without asking.
func confirmNo(out io.Writer, in io.Reader, question string) bool {
	return askYesNo(out, in, question, false)
}

func askYesNo(out io.Writer, in io.Reader, question string, defaultYes bool) bool {
	if !isTerminal(in) {
		return false
	}

	hint := "[Y/n]"
	if !defaultYes {
		hint = "[y/N]"
	}
	fmt.Fprintf(out, "%s %s: ", question, hint)
	line, err := lineReader(in).ReadString('\n')
	if err != nil && !errors.Is(err, io.EOF) {
		return false
	}
	// Ctrl-D gives EOF with nothing typed. Reading that as the Enter default
	// would write a file the person was in the middle of declining.
	if errors.Is(err, io.EOF) && "" == line {
		return false
	}

	switch strings.ToLower(strings.TrimSpace(line)) {
	case "":
		return defaultYes
	case "y", "yes":
		return true
	default:
		return false
	}
}

// lineReader reads lines from in. A reader that already buffers is used as it
// is, so several questions in a row do not lose the answers to the later ones.
func lineReader(in io.Reader) *bufio.Reader {
	if br, ok := in.(*bufio.Reader); ok {
		return br
	}

	return bufio.NewReader(in)
}

// isTerminal reports whether somebody can answer on in. A file counts only
// when it is a real terminal: /dev/null is a character device and fails the
// test. Any other reader counts, so tests can feed answers.
func isTerminal(in io.Reader) bool {
	f, ok := in.(*os.File)
	if !ok {
		return true
	}

	return term.IsTerminal(int(f.Fd()))
}

// projectChoice is the answer of chooseProject: a project id, or create when
// the user picked the row that makes a new project.
type projectChoice struct {
	id     string
	create bool
}

// chooseProject asks which project the file should name. It always asks, also
// for one project, because a silent pick links a repository to the wrong
// project. With canCreate the list ends with a row that creates one.
func chooseProject(out io.Writer, in io.Reader, sites []api.Site, canCreate bool) (projectChoice, error) {
	if len(sites) == 0 && !canCreate {
		return projectChoice{}, errors.New("your login covers no project: create one in Loupe first")
	}

	terminal := isTerminal(in)
	if terminal {
		fmt.Fprintln(out, "Which project does this repository belong to?")
	} else {
		fmt.Fprintln(out, "Your login covers these projects:")
	}
	for i, site := range sites {
		fmt.Fprintf(out, "  %d) %s\n", i+1, describe(site))
	}
	if !terminal {
		return projectChoice{}, errors.New(noAnswerMessage(canCreate))
	}

	rows := len(sites)
	if canCreate {
		rows++
		fmt.Fprintf(out, "  %d) Create a new project\n", rows)
	}
	fmt.Fprintf(out, "Choose 1 to %d: ", rows)

	line, err := lineReader(in).ReadString('\n')
	if err != nil && !errors.Is(err, io.EOF) {
		return projectChoice{}, err
	}

	n, err := strconv.Atoi(strings.TrimSpace(line))
	if err != nil || n < 1 || n > rows {
		return projectChoice{}, fmt.Errorf("choose a number between 1 and %d, or pass --project", rows)
	}
	if n > len(sites) {
		return projectChoice{create: true}, nil
	}

	return projectChoice{id: sites[n-1].ID}, nil
}

func noAnswerMessage(canCreate bool) string {
	if canCreate {
		return `nobody can answer here: pass --project <slug> to use one of these projects, or --name "<name>" to create a new one`
	}

	return "nobody can answer here: pass --project <slug> to use one of these projects"
}

// describe names a project the way the chooser shows it. A project with no slug
// yet shows its id, which is what the file will hold.
func describe(site api.Site) string {
	if site.Slug == "" {
		return fmt.Sprintf("%s (%s)", site.Name, site.ID)
	}

	return fmt.Sprintf("%s (%s, %s)", site.Name, site.Slug, site.ID)
}
