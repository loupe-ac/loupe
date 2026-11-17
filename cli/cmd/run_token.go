package cmd

import (
	"errors"
	"os"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

// runCredential is the run token of a bridge worker and the address of the
// server that issued it.
type runCredential struct {
	baseURL string
	token   api.StaticToken
}

// runCredentialFromEnv reads the run path. With no LOUPE_RUN_TOKEN it returns
// nil, and the command goes on with its key or its stored login. With the token
// set, the command reads no file of the login folder. It takes its address
// from LOUPE_URL and never falls back to the hosted instance, so the token
// never goes to a server that did not issue it.
func runCredentialFromEnv() (*runCredential, error) {
	token := strings.TrimSpace(os.Getenv(runTokenEnv))
	if token == "" {
		return nil, nil
	}
	base := strings.TrimRight(strings.TrimSpace(os.Getenv(runURLEnv)), "/")
	if base == "" {
		return nil, &exitError{exitAuth, errors.New(runTokenEnv + " is set but " + runURLEnv + " is not, so the token has no server to go to")}
	}

	return &runCredential{baseURL: base, token: api.StaticToken(token)}, nil
}
