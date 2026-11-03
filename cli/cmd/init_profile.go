package cmd

import (
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strconv"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/profile"
	"github.com/loupe-ac/loupe/cli/internal/skills"
	"github.com/spf13/cobra"
)

const (
	agentClaude = "claude"
	agentCodex  = "codex"

	// profilePackage holds the profile skill, and profileSkill is its name.
	profilePackage = "loupe-lifecycle"
	profileSkill   = "loupe-profile"
)

// initAgent runs an agent in the foreground, on the terminal of init. A test
// replaces it.
var initAgent = func(dir string, in io.Reader, out io.Writer, name string, args ...string) error {
	c := exec.Command(name, args...)
	c.Dir = dir
	c.Stdin, c.Stdout, c.Stderr = in, out, os.Stderr

	return c.Run()
}

// initProfile starts the agent that writes the repository profile with the
// owner. It runs only for a project whose workflow needs a profile, and only
// when the file is missing, lacks a section or holds an open value. It never
// fails init: when it cannot start the agent, it prints the prompt to paste.
func initProfile(cmd *cobra.Command, dir string, site api.Site, o initOptions) {
	out := cmd.OutOrStdout()

	if !site.ProfileNeeded() {
		step(out, "Profile", "skipped", "the workflow of this project needs no profile")

		return
	}
	status, err := profile.Check(dir)
	if err != nil {
		step(out, "Profile", "failed", err.Error())

		return
	}
	if status.Complete() && !o.reviewProfile {
		step(out, "Profile", "skipped", profile.Path+" is complete. Pass --review-profile to review it")

		return
	}

	paths, packages := profilePackageDirs(site)
	skillPath := ""
	if dir := paths[profilePackage]; dir != "" {
		skillPath = filepath.Join(dir, "skills", profileSkill, "SKILL.md")
	}
	prompt := profilePrompt(site, skillPath, o.reviewProfile)

	agent, why := pickProfileAgent(cmd, o.agent, skillPath != "")
	if agent == "" {
		step(out, "Profile", "skipped", why+". Paste this prompt into an agent in this folder:")
		fmt.Fprintln(out)
		fmt.Fprintln(out, prompt)

		return
	}

	step(out, "Profile", "started", status.Reason()+". The "+agent+" agent writes it with you")
	args := []string{prompt}
	if agent == agentClaude {
		args = []string{}
		for _, p := range packages {
			args = append(args, "--plugin-dir", paths[p])
		}
		args = append(args, "--", prompt)
	}
	if err := initAgent(dir, cmd.InOrStdin(), out, agent, args...); err != nil {
		step(out, "Profile", "failed", agent+" stopped: "+err.Error()+". Paste this prompt into an agent in this folder:")
		fmt.Fprintln(out)
		fmt.Fprintln(out, prompt)

		return
	}

	after, err := profile.Check(dir)
	switch {
	case err != nil:
		step(out, "Profile", "failed", err.Error())
	case after.Complete():
		step(out, "Profile", "done", profile.Path+" is complete. Commit it and push it, because a worker sees only what you push")
	default:
		step(out, "Profile", "open", after.Reason()+". Run `loupe init --review-profile` to go on")
	}
}

// pickProfileAgent chooses the agent. It returns an empty name and the reason
// when init must print the prompt instead.
func pickProfileAgent(cmd *cobra.Command, flag string, haveSkill bool) (string, string) {
	if !haveSkill {
		return "", "this build of loupe holds no copy of the " + profilePackage + " skills"
	}
	if flag != "" && flag != agentClaude && flag != agentCodex {
		return "", "--agent takes " + agentClaude + " or " + agentCodex
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
	in := cmd.InOrStdin()
	switch {
	case len(found) == 0:
		return "", "no agent is on your PATH"
	case !isTerminal(in):
		return "", "no terminal to run an agent on"
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
// path too, because Codex has no way to load a package folder.
func profilePrompt(site api.Site, skillPath string, review bool) string {
	var b strings.Builder
	b.WriteString("Write the Loupe repository profile, " + profile.Path + ", for this repository with me. ")
	b.WriteString("Use the " + profilePackage + ":" + profileSkill + " skill")
	if skillPath != "" {
		b.WriteString(", at " + skillPath)
	}
	b.WriteString(". The Loupe project is " + site.ID + ".")
	if review {
		b.WriteString(" The file exists. Review every value of it against the repository.")
	}

	return b.String()
}

// profilePackageDirs unpacks the starting copy into the store, and returns the
// newest folder of each package that the project uses, with the package names
// in the order of use. A package the store lacks is left out.
func profilePackageDirs(site api.Site) (map[string]string, []string) {
	store := config.Current().Dir
	_, _ = skills.Install(store)

	held, _ := os.ReadDir(filepath.Join(store, "skills"))
	var names []string
	for _, e := range held {
		if e.IsDir() && e.Name() != "backup" {
			names = append(names, e.Name())
		}
	}
	dirs := map[string]string{}
	var used []string
	for _, name := range site.PackagesIn(names) {
		if dir := newestVersionDir(filepath.Join(store, "skills", name)); dir != "" {
			dirs[name] = dir
			used = append(used, name)
		}
	}

	return dirs, used
}

// newestVersionDir returns the folder with the highest version under root.
func newestVersionDir(root string) string {
	entries, err := os.ReadDir(root)
	if err != nil {
		return ""
	}
	best := ""
	for _, e := range entries {
		if e.IsDir() && (best == "" || versionLess(best, e.Name())) {
			best = e.Name()
		}
	}
	if best == "" {
		return ""
	}

	return filepath.Join(root, best)
}

// versionLess compares two dotted versions by their numbers.
func versionLess(a, b string) bool {
	pa, pb := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < max(len(pa), len(pb)); i++ {
		var x, y int
		if i < len(pa) {
			x, _ = strconv.Atoi(strings.SplitN(pa[i], "-", 2)[0])
		}
		if i < len(pb) {
			y, _ = strconv.Atoi(strings.SplitN(pb[i], "-", 2)[0])
		}
		if x != y {
			return x < y
		}
	}

	return false
}
