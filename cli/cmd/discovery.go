package cmd

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/profile"
	"github.com/spf13/cobra"
)

const discoveryTimeout = 2 * time.Minute

// startDiscoveryQuestion is the question of the confirmation before a start.
const startDiscoveryQuestion = "Start discovery now? It runs a worker that checks the repository and lists what remains."

// startDiscovery calls the API. A test replaces it.
var startDiscovery = func(ctx context.Context, c *api.Client, project string) (api.Discovery, error) {
	return c.StartDiscovery(ctx, project)
}

// discoveryLink gives the address to print for a started run. It is the page
// the server sends, else the Workshop page of the project.
func discoveryLink(baseURL string, projectID string, d api.Discovery) string {
	if d.URL != "" {
		return d.URL
	}

	return strings.TrimRight(baseURL, "/") + "/projects/" + projectID
}

// profileReason says why the profile is not finished, or "" when it is or the
// project needs none.
func profileReason(dir string, site api.Site) string {
	if !site.ProfileNeeded() {
		return ""
	}
	status, err := profile.Check(dir)
	switch {
	case err != nil:
		return "the check failed: " + err.Error()
	case status.Complete():
		return ""
	default:
		return status.Reason()
	}
}

// initDiscovery offers to start discovery when onboarding is ready: the profile
// is complete, the accounts pass, the files are pushed, and a run is due. It
// asks once. With no terminal, confirm answers no, so nothing starts. It never
// fails init.
func initDiscovery(cmd *cobra.Command, cfg config.Config, dir string, site api.Site, left *initLeft) {
	if !wantsDiscovery(site, *left) || len(discoveryBlockers(*left, filesNotPushed(dir, site))) > 0 {
		return
	}
	out := cmd.OutOrStdout()
	if !confirm(out, cmd.InOrStdin(), startDiscoveryQuestion) {
		return
	}

	ctx, cancel := context.WithTimeout(cmd.Context(), discoveryTimeout)
	defer cancel()
	d, err := startDiscovery(ctx, apiClient(cfg), site.ID)
	if err != nil {
		step(out, "Discovery", "failed", err.Error())

		return
	}
	left.discoveryStarted = true
	fmt.Fprintf(out, "Discovery started: %s\n", discoveryLink(cfg.BaseURL, site.ID, d))
}

func newDiscoveryCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "discovery",
		Short: "Start and read discovery runs of a project (beta)",
		Long:  "Start and read discovery runs of a project.\n\n" + betaNote,
	}
	cmd.AddCommand(newDiscoveryStartCmd())

	return cmd
}

func newDiscoveryStartCmd() *cobra.Command {
	var project string
	var force bool

	cmd := &cobra.Command{
		Use:   "start",
		Short: "Start a discovery run for the project",
		Long: "Starts a discovery run. A worker checks the repository against the profile and lists " +
			"what remains before each kind of work can run. It prints the link of the Workshop page, which opens the report when it is written.\n\n" +
			"Before the call, the command checks the gate that `loupe init` uses: the profile is " +
			"complete, the rule file reads, no account that a rule uses fails for the project, and the setup files are " +
			"committed and pushed. It reads the current folder, so run it in the repository. " +
			"--force skips the gate. The gate needs a stored login. With --force, LOUPE_API_KEY serves too.\n\n" +
			"The project comes from --project, LOUPE_PROJECT, the project link, or " + ".loupe.yaml.\n\n" + betaNote,
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			return runDiscoveryStart(cmd, project, force)
		},
	}
	cmd.SetFlagErrorFunc(usageFlagError)
	cmd.Flags().StringVar(&project, "project", "", "project id or slug (else "+projectEnv+", the project link, or .loupe.yaml)")
	cmd.Flags().BoolVar(&force, "force", false, "start without checking the gate")

	return cmd
}

func runDiscoveryStart(cmd *cobra.Command, projectFlag string, force bool) error {
	ctx := cmd.Context()
	if ctx == nil {
		ctx = context.Background()
	}
	s, err := publicClient(cmd)
	if err != nil {
		return err
	}
	handle, err := publicProject(ctx, s, projectFlag, sendOptions{})
	if err != nil {
		return err
	}
	id := handle
	if !force {
		site, sites, err := discoverySite(ctx, handle)
		if err != nil {
			return err
		}
		if err := checkDiscoveryGate(ctx, site, sites); err != nil {
			return err
		}
		id = site.ID
	}

	callCtx, cancel := context.WithTimeout(ctx, discoveryTimeout)
	defer cancel()
	d, err := startDiscovery(callCtx, s.client, id)
	if err != nil {
		return &exitError{1, err}
	}
	fmt.Fprintf(cmd.OutOrStdout(), "Discovery started: %s\n", discoveryLink(s.client.BaseURL(), id, d))

	return nil
}

// discoverySite finds the project in the list of the stored login.
func discoverySite(ctx context.Context, handle string) (api.Site, []api.Site, error) {
	cfg, err := config.Load()
	if err != nil {
		return api.Site{}, nil, &exitError{exitAuth, fmt.Errorf("the gate needs a stored login (%w). Run `loupe login`, or pass --force", err)}
	}
	listCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	sites, err := apiClient(cfg).Sites(listCtx)
	if err != nil {
		return api.Site{}, nil, &exitError{1, fmt.Errorf("list the projects: %w", err)}
	}
	for _, s := range sites {
		if strings.EqualFold(s.ID, handle) || (s.Slug != "" && s.Slug == handle) {
			return s, sites, nil
		}
	}

	return api.Site{}, nil, &exitError{exitUsage, fmt.Errorf("the login lists no project %q. Pass --force to skip the gate", handle)}
}

// checkDiscoveryGate runs the checks of the init gate in the current folder.
func checkDiscoveryGate(ctx context.Context, site api.Site, sites []api.Site) error {
	dir, err := os.Getwd()
	if err != nil {
		return err
	}
	var left initLeft
	left.profile = profileReason(dir, site)
	if path, err := defaultRulesPath(); err != nil {
		left.accountCheck = "cannot find the rule file: " + err.Error()
	} else {
		left.accounts, left.accountCheck = reportAccountsStep(ctx, io.Discard, path, site, sites)
	}
	blockers := discoveryBlockers(left, filesNotPushed(dir, site))
	if len(blockers) == 0 {
		return nil
	}

	return &exitError{1, errors.New("discovery is not ready to start:\n- " + strings.Join(blockers, "\n- ") + "\nFix these, or pass --force to start anyway.")}
}
