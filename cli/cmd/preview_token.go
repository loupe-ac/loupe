package cmd

import (
	"errors"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
)

// previewTokenTimeout bounds one mint or revoke. A worktree script waits for it.
const previewTokenTimeout = 20 * time.Second

func newPreviewTokenCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "preview-token",
		Short: "Mint or revoke the sign-in token of a preview site",
		Long: "A preview token signs the site-review widget in on a preview page with no popup. " +
			"`just worktree-up` mints one for each worktree, and `just worktree-down` revokes it.\n\n" +
			"A token lives 14 days at most. A project owner revokes any token in Account > Connected apps.",
	}
	cmd.AddCommand(newPreviewTokenMintCmd(), newPreviewTokenRevokeCmd())

	return cmd
}

// previewTokenFlags are the flags both subcommands share.
type previewTokenFlags struct {
	project, label, instance string
}

func (f *previewTokenFlags) bind(cmd *cobra.Command) {
	cmd.Flags().StringVar(&f.project, "project", "", "the `id` or slug of the Loupe project")
	cmd.Flags().StringVar(&f.label, "label", "", "the name of the preview, such as the worktree name")
	cmd.Flags().StringVar(&f.instance, "instance", "", "refuse unless you are signed in to this Loupe instance `url`")
	_ = cmd.MarkFlagRequired("project")
	_ = cmd.MarkFlagRequired("label")
}

// previewTokenClient signs in from the stored login. A test replaces it.
var previewTokenClient = func(f previewTokenFlags) (*api.Client, error) {
	cfg, err := config.Load()
	if err != nil {
		if errors.Is(err, config.ErrNotLoggedIn) {
			return nil, errors.New("you are not signed in: run `loupe login`")
		}

		return nil, err
	}
	if f.instance != "" && strings.TrimRight(f.instance, "/") != strings.TrimRight(cfg.BaseURL, "/") {
		return nil, fmt.Errorf("you are signed in to %s, not to %s", cfg.BaseURL, f.instance)
	}
	hc := &http.Client{Timeout: previewTokenTimeout, Transport: withUserAgent(nil)}

	return api.NewWithSource(cfg.BaseURL, tokenSource(cfg, hc), hc), nil
}

func newPreviewTokenMintCmd() *cobra.Command {
	var f previewTokenFlags
	var card string

	cmd := &cobra.Command{
		Use:   "mint",
		Short: "Print a new preview token",
		Long: "Asks your Loupe instance for a preview token of one project and prints it on one line. " +
			"A second mint with the same label revokes the first token.\n\n" +
			"With --card, the token carries the card of the preview. The command exits 1 with the " +
			"reason on stderr when the server refuses.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			client, err := previewTokenClient(f)
			if err != nil {
				return err
			}
			token, err := client.MintPreviewToken(cmd.Context(), f.project, f.label, card)
			if err != nil {
				return err
			}
			fmt.Fprintln(cmd.OutOrStdout(), token)

			return nil
		},
	}
	f.bind(cmd)
	cmd.Flags().StringVar(&card, "card", "", "the `id` of the card the preview shows")

	return cmd
}

func newPreviewTokenRevokeCmd() *cobra.Command {
	var f previewTokenFlags

	cmd := &cobra.Command{
		Use:   "revoke",
		Short: "Revoke the preview tokens of a label",
		Long:  "Revokes your live preview tokens of one label on one project. It exits 0 when no live token has the label.",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			client, err := previewTokenClient(f)
			if err != nil {
				return err
			}
			n, err := client.RevokePreviewTokens(cmd.Context(), f.project, f.label)
			if err != nil {
				return err
			}
			fmt.Fprintf(cmd.OutOrStdout(), "Revoked %d preview token(s) of %s.\n", n, f.label)

			return nil
		},
	}
	f.bind(cmd)

	return cmd
}
