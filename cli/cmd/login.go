package cmd

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/oauth"
	"github.com/spf13/cobra"
)

// DefaultBaseURL is the instance `loupe login` signs in to when nothing names
// another. The hosted instance, because that is where a reader of a release
// binary has an account. Someone self-hosting, or working on Loupe itself,
// passes --url once or sets LOUPE_URL.
const DefaultBaseURL = "https://app.loupe.ac"

// resolveBaseURL picks the instance to sign in to: the flag, else LOUPE_URL,
// else the hosted instance. The trailing slash goes, because every caller joins
// a path onto it.
// It refuses plain HTTP to another machine unless allowHTTP is true.
func resolveBaseURL(flag, env string, allowHTTP bool) (string, error) {
	picked := DefaultBaseURL
	for _, candidate := range []string{flag, env} {
		if trimmed := strings.TrimRight(strings.TrimSpace(candidate), "/"); "" != trimmed {
			picked = trimmed

			break
		}
	}
	if err := config.CheckServerURL(picked, allowHTTP); err != nil {
		return "", err
	}

	return picked, nil
}

func newLoginCmd() *cobra.Command {
	var baseURL string
	var allowHTTP bool

	cmd := &cobra.Command{
		Use:   "login",
		Short: "Sign the bridge in to Loupe",
		Long: "Signs the bridge in to Loupe, so it can subscribe to your site's event stream.\n\n" +
			"login prints a link and a code. Open the link in a browser where you are signed in " +
			"to Loupe, check the code, and choose Allow. The CLI then stores an access token and " +
			"a refresh token, and refreshes the access token by itself.\n\n" +
			"A browser is the only way in. Loupe issues no credential a person cannot see, so a " +
			"machine with no browser anywhere cannot sign in.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			allow := allowHTTP || config.AllowHTTPFromEnv()
			resolved, err := resolveBaseURL(baseURL, os.Getenv("LOUPE_URL"), allow)
			if err != nil {
				return err
			}

			return deviceLogin(cmd, resolved, allow)
		},
	}
	cmd.Flags().BoolVar(&allowHTTP, "allow-http", false, "allow plain http:// to a host that is not this machine, when the network is trusted (or set "+config.AllowHTTPEnv+"=1)")
	cmd.Flags().StringVar(&baseURL, "url", "", "Loupe base URL (else LOUPE_URL env, else "+DefaultBaseURL+")")

	return cmd
}

// deviceLogin runs the device flow: it prints the page and the code, waits for
// the person to approve them, and stores the tokens. It prints no token.
func deviceLogin(cmd *cobra.Command, baseURL string, allowHTTP bool) error {
	out := cmd.OutOrStdout()

	err := runDeviceFlow(cmd.Context(), baseURL, allowHTTP, func(device oauth.Device) {
		code := oauth.DisplayUserCode(device.UserCode)
		if device.VerificationURIComplete != "" {
			fmt.Fprintf(out, "Open this page in a browser where you are signed in to Loupe:\n\n  %s\n\nCheck that the page shows the code %s, then choose Allow.\n", device.VerificationURIComplete, code)
		} else {
			fmt.Fprintf(out, "Open this page in a browser where you are signed in to Loupe:\n\n  %s\n\nType the code %s, then choose Allow.\n", device.VerificationURI, code)
		}
		fmt.Fprintln(out, "Waiting for your answer...")
	})
	if err != nil {
		return err
	}
	fmt.Fprintln(out, "Logged in. Tokens saved.")

	return nil
}

// runDeviceFlow starts the device flow, hands the grant to announce, waits for
// the approval, checks the token and stores it.
func runDeviceFlow(parent context.Context, baseURL string, allowHTTP bool, announce func(oauth.Device)) error {
	ctx, stop := signal.NotifyContext(parent, os.Interrupt)
	defer stop()

	hc := deviceFlowClient(allowHTTP)
	flow := oauth.NewFlow(baseURL, hc)
	device, err := flow.Start(ctx)
	if err != nil {
		return err
	}
	announce(device)

	tokens, err := flow.Poll(ctx, device)
	if err != nil {
		return err
	}

	return saveDeviceLogin(ctx, baseURL, allowHTTP, hc, tokens)
}

// deviceFlowClient is the HTTP client of a device sign-in.
func deviceFlowClient(allowHTTP bool) *http.Client {
	return &http.Client{Timeout: refreshTimeout, Transport: withUserAgent(nil, allowHTTP)}
}

// saveDeviceLogin checks the tokens of a device sign-in with one real call,
// then saves them as the login. A new login ends any pending review sign-in.
func saveDeviceLogin(ctx context.Context, baseURL string, allowHTTP bool, hc *http.Client, tokens config.OAuthTokens) error {
	checkCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	if _, err := api.New(baseURL, tokens.AccessToken, hc).Sites(checkCtx); err != nil {
		return err
	}
	if err := config.Save(config.Config{BaseURL: baseURL, OAuth: &tokens, AllowHTTP: allowHTTP}); err != nil {
		return err
	}

	return config.ClearPendingSignIn()
}
