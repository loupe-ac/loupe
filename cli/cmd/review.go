package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"regexp"
	"runtime"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/oauth"
)

// maxReviewInput caps the note `loupe review save` reads. A note holds a base64
// screenshot of about 3 MB.
const maxReviewInput = 4 << 20

const uuidPattern = `[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}`

var lowerUUID = regexp.MustCompile(`^` + uuidPattern + `$`)

// noteScope matches the destinations the widget lists notes for: one card, the
// children of an epic, or the cards of one review session, at most 50.
var noteScope = regexp.MustCompile(`^(?:(?:card|epic):` + uuidPattern + `|cards:` + uuidPattern + `(?:,` + uuidPattern + `){0,49})$`)

// reviewError is the failure a review helper prints as its one JSON object.
type reviewError struct {
	Code    string `json:"error"`
	Message string `json:"message"`
}

func (e reviewError) Error() string { return e.Code + ": " + e.Message }

// reviewFail turns an error into the error object of a helper.
func reviewFail(err error) reviewError {
	var failure reviewError
	switch {
	case errors.As(err, &failure):
		return failure
	case errors.Is(err, api.ErrProjectNotCovered):
		return reviewError{"not_owner", "this login does not cover the project"}
	case signedOut(err):
		return reviewError{"signed_out", "sign in again to allow site review"}
	}

	return reviewError{"request_failed", err.Error()}
}

// signedOut reports an error that only a new sign-in fixes.
func signedOut(err error) bool {
	return errors.Is(err, api.ErrScopeMissing) || errors.Is(err, config.ErrNotLoggedIn) || errors.Is(err, oauth.ErrLoginExpired)
}

// reviewPrint writes one JSON object and a newline.
func reviewPrint(cmd *cobra.Command, v any) error {
	data, err := json.Marshal(v)
	if err != nil {
		return err
	}
	_, err = fmt.Fprintf(cmd.OutOrStdout(), "%s\n", data)

	return err
}

// reviewRun wraps a helper so that a failure prints its error object on stdout.
func reviewRun(run func(cmd *cobra.Command) error) func(*cobra.Command, []string) error {
	return func(cmd *cobra.Command, _ []string) error {
		err := run(cmd)
		if err == nil {
			return nil
		}
		failure := reviewFail(err)
		_ = reviewPrint(cmd, failure)

		return failure
	}
}

func checkReviewProject(id string) error {
	if !lowerUUID.MatchString(id) {
		return reviewError{"invalid_project", "--project must be a lower-case project id"}
	}

	return nil
}

func newReviewCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:    "review",
		Short:  "Helpers for the Live review panel",
		Hidden: true,
	}
	cmd.AddCommand(newReviewStatusCmd(), newReviewSignInCmd(), newReviewProjectsCmd(), newReviewTargetsCmd(), newReviewNotesCmd(), newReviewSaveCmd())

	return cmd
}

// reviewClient loads the login and builds a client for one project.
func reviewClient(project string) (*api.Client, error) {
	cfg, err := config.Load()
	if err != nil {
		return nil, err
	}

	return apiClient(cfg).WithProject(project), nil
}

func newReviewStatusCmd() *cobra.Command {
	var project string
	cmd := &cobra.Command{Use: "status", Short: "Report the sign-in state", Hidden: true}
	cmd.Flags().StringVar(&project, "project", "", "project id")
	cmd.RunE = reviewRun(func(cmd *cobra.Command) error {
		state := func(s string) error { return reviewPrint(cmd, map[string]string{"state": s}) }
		if project != "" {
			if err := checkReviewProject(project); err != nil {
				return err
			}
		}
		cfg, err := config.Load()
		if signedOut(err) {
			return state("signedOut")
		}
		if err != nil {
			return err
		}
		client := apiClient(cfg)
		probe := project
		if probe == "" {
			sites, err := client.Sites(cmd.Context())
			if signedOut(err) {
				return state("signedOut")
			}
			if err != nil {
				return err
			}
			if len(sites) == 0 {
				return state("noProject")
			}
			probe = sites[0].ID
		}
		_, err = client.WithProject(probe).SiteReviewNotes(cmd.Context(), "")
		switch {
		case err == nil:
			return state("ok")
		case errors.Is(err, api.ErrProjectNotCovered):
			return state("notOwner")
		case signedOut(err):
			return state("signedOut")
		}

		return err
	})

	return cmd
}

func newReviewSignInCmd() *cobra.Command {
	cmd := &cobra.Command{Use: "sign-in", Short: "Sign in with the device flow", Hidden: true}
	cmd.RunE = reviewRun(func(cmd *cobra.Command) error {
		baseURL := resolveBaseURL("", os.Getenv("LOUPE_URL"))
		if os.Getenv("LOUPE_URL") == "" {
			if cfg, err := config.Load(); err == nil {
				baseURL = resolveBaseURL(cfg.BaseURL, "")
			}
		}

		err := runDeviceFlow(cmd.Context(), baseURL, func(device oauth.Device) {
			link := device.VerificationURIComplete
			if link == "" {
				link = device.VerificationURI
			}
			_ = reviewPrint(cmd, map[string]string{"code": oauth.DisplayUserCode(device.UserCode), "url": link})
			if runtime.GOOS == "darwin" {
				_ = exec.Command("open", link).Start()
			}
		})
		if err != nil {
			return err
		}

		return reviewPrint(cmd, map[string]string{"state": "ok"})
	})

	return cmd
}

func newReviewProjectsCmd() *cobra.Command {
	cmd := &cobra.Command{Use: "projects", Short: "List the projects of the login", Hidden: true}
	cmd.RunE = reviewRun(func(cmd *cobra.Command) error {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		sites, err := apiClient(cfg).Sites(cmd.Context())
		if err != nil {
			return err
		}
		type project struct {
			ID   string `json:"id"`
			Name string `json:"name"`
		}
		list := make([]project, 0, len(sites))
		for _, s := range sites {
			list = append(list, project{s.ID, s.Name})
		}

		return reviewPrint(cmd, map[string]any{"projects": list})
	})

	return cmd
}

// newReviewReadCmd builds a helper that reads from the server for one project.
// flags adds the options of the helper, and check refuses a bad option before
// any request.
func newReviewReadCmd(use, key string, flags func(cmd *cobra.Command), check func() error, read func(ctx context.Context, c *api.Client) (json.RawMessage, error)) *cobra.Command {
	var project string
	cmd := &cobra.Command{Use: use, Hidden: true}
	cmd.Flags().StringVar(&project, "project", "", "project id (required)")
	flags(cmd)
	cmd.RunE = reviewRun(func(cmd *cobra.Command) error {
		if err := checkReviewProject(project); err != nil {
			return err
		}
		if err := check(); err != nil {
			return err
		}
		client, err := reviewClient(project)
		if err != nil {
			return err
		}
		answer, err := read(cmd.Context(), client)
		if err != nil {
			return err
		}

		return reviewPrint(cmd, map[string]json.RawMessage{key: answer})
	})

	return cmd
}

func newReviewTargetsCmd() *cobra.Command {
	var query string
	var parent bool

	return newReviewReadCmd("targets", "cards", func(cmd *cobra.Command) {
		cmd.Flags().StringVar(&query, "query", "", "search text")
		cmd.Flags().BoolVar(&parent, "parent", false, "list only the cards that can hold children")
	}, func() error { return nil }, func(ctx context.Context, c *api.Client) (json.RawMessage, error) {
		return c.SiteReviewTargets(ctx, query, parent)
	})
}

func newReviewNotesCmd() *cobra.Command {
	var scope string

	return newReviewReadCmd("notes", "notes", func(cmd *cobra.Command) {
		cmd.Flags().StringVar(&scope, "scope", "", "destination: card:<id>, epic:<id> or cards:<id>,<id> (required)")
	}, func() error {
		if !noteScope.MatchString(scope) {
			return reviewError{"invalid_scope", "--scope must be card:<id>, epic:<id> or cards:<id>,<id> with lower-case ids"}
		}

		return nil
	}, func(ctx context.Context, c *api.Client) (json.RawMessage, error) {
		return c.SiteReviewNotes(ctx, scope)
	})
}

func newReviewSaveCmd() *cobra.Command {
	var project string
	cmd := &cobra.Command{Use: "save", Short: "Save one note read from stdin", Hidden: true}
	cmd.Flags().StringVar(&project, "project", "", "project id (required)")
	cmd.RunE = reviewRun(func(cmd *cobra.Command) error {
		if err := checkReviewProject(project); err != nil {
			return err
		}
		data, err := io.ReadAll(io.LimitReader(cmd.InOrStdin(), maxReviewInput+1))
		if err != nil {
			return err
		}
		if len(data) > maxReviewInput {
			return reviewError{"input_too_large", "the note is larger than 4 MiB"}
		}
		var object map[string]json.RawMessage
		if json.Unmarshal(data, &object) != nil || object == nil {
			return reviewError{"invalid_input", "stdin must hold one JSON object"}
		}
		client, err := reviewClient(project)
		if err != nil {
			return err
		}
		answer, err := client.SaveSiteReviewNote(cmd.Context(), data)
		if err != nil {
			return err
		}

		return reviewPrint(cmd, answer)
	})

	return cmd
}
