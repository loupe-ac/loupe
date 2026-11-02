package api

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"slices"
)

// ServedVersion is the API version this CLI speaks.
const ServedVersion = "v1"

// ErrServerTooOld marks a server that has no GET /api/versions route, so it
// serves none of the paths this CLI calls.
var ErrServerTooOld = errors.New("the server is too old for this CLI: it has no GET /api/versions route, so upgrade Loupe first")

// ErrVersionUnserved marks a server that answers /api/versions without the
// version this CLI speaks.
var ErrVersionUnserved = errors.New("the server does not serve the API version " + ServedVersion + " this CLI speaks")

// Versions is the answer of GET /api/versions: the API versions the server
// serves and the range of CLI versions it works with.
type Versions struct {
	Versions []struct {
		Version string `json:"version"`
		Status  string `json:"status"`
	} `json:"versions"`
	CLIRange string `json:"cliRange"`
}

// Serves reports whether the server serves the given API version.
func (v Versions) Serves(version string) bool {
	return slices.ContainsFunc(v.Versions, func(entry struct {
		Version string `json:"version"`
		Status  string `json:"status"`
	}) bool {
		return entry.Version == version
	})
}

// Versions reads GET /api/versions. The route needs no sign-in, so the call
// sends no token and works before a login. A plain 404 means the server
// predates the route.
func (c *Client) Versions(ctx context.Context) (Versions, error) {
	var out Versions
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+"/api/versions", nil)
	if err != nil {
		return out, err
	}
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return out, fmt.Errorf("request the API versions: %w", err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusNotFound:
		return out, ErrServerTooOld
	default:
		return out, fmt.Errorf("the API versions request failed (HTTP %d)", resp.StatusCode)
	}
	if err := decodeBody(resp.Body, &out); err != nil {
		return out, fmt.Errorf("decode the API versions: %w", err)
	}

	return out, nil
}

// CheckServer reads the versions and refuses a server that is too old or does
// not serve ServedVersion. Any other failure passes with empty versions, because
// the first authenticated call then reports the unreachable server.
func (c *Client) CheckServer(ctx context.Context) (Versions, error) {
	versions, err := c.Versions(ctx)
	switch {
	case errors.Is(err, ErrServerTooOld):
		return versions, err
	case err != nil:
		return Versions{}, nil
	case !versions.Serves(ServedVersion):
		return versions, ErrVersionUnserved
	}

	return versions, nil
}
