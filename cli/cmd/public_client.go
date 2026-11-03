package cmd

import (
	"errors"
	"net/http"
	"os"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/spf13/cobra"
)

const (
	apiKeyEnv  = "LOUPE_API_KEY"
	publicWait = 5 * time.Minute
)

// publicSession is the client of one public command and the kind of credential
// behind it.
type publicSession struct {
	client *api.Client
	// usesKey is true for LOUPE_API_KEY and false for the stored login.
	usesKey bool
}

// publicClient builds the client of a public command. A key in LOUPE_API_KEY
// wins, goes to LOUPE_URL else the hosted instance, and touches no config file.
// With no key, the stored login serves. Only the public commands call this, so
// the bridge and `loupe init` keep the login.
func publicClient(_ *cobra.Command) (*publicSession, error) {
	hc := &http.Client{Timeout: publicWait, Transport: withUserAgent(nil)}

	if key := strings.TrimSpace(os.Getenv(apiKeyEnv)); key != "" {
		return &publicSession{client: api.New(resolveBaseURL("", os.Getenv("LOUPE_URL")), key, hc), usesKey: true}, nil
	}

	cfg, err := config.Load()
	if err != nil {
		if errors.Is(err, config.ErrNotLoggedIn) {
			return nil, &exitError{exitAuth, errors.New(err.Error() + ", or set " + apiKeyEnv)}
		}

		return nil, err
	}
	refresh := &http.Client{Timeout: refreshTimeout, Transport: withUserAgent(nil)}

	return &publicSession{client: api.NewWithSource(cfg.BaseURL, tokenSource(cfg, refresh), hc)}, nil
}
