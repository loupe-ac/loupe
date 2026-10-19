package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
)

// CreateProjectRequest is the body of POST /api/projects. The server wants a
// name, a remote, or both, and it names a project after its remote when the
// name is empty. An empty field is left out of the body.
type CreateProjectRequest struct {
	Name    string `json:"name,omitempty"`
	Website string `json:"website,omitempty"`
	Remote  string `json:"remote,omitempty"`
}

// The errors CreateProject maps from the answers of the server.
var (
	// ErrCredentialNotOwnerWide marks a token that is limited to some projects.
	// Only a token that covers all of them can create one.
	ErrCredentialNotOwnerWide = errors.New("this login is limited to some projects, so it cannot create one: log in again with `loupe login`")
	// ErrProjectNameTaken marks a name that another project of the owner has.
	ErrProjectNameTaken = errors.New("another project of yours has this name: choose another name")
	// ErrProjectNameRequired marks a request with no name and no usable remote.
	ErrProjectNameRequired = errors.New("the project needs a name")
	// ErrProjectNameInvalid marks a name that gives no project slug.
	ErrProjectNameInvalid = errors.New("the project name has no letter or digit, so it gives no slug: choose another name")
)

// CreateProject creates a project for the caller and returns its id, slug and
// name.
func (c *Client) CreateProject(ctx context.Context, in CreateProjectRequest) (Site, error) {
	body, err := json.Marshal(in)
	if err != nil {
		return Site{}, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/api/projects", bytes.NewReader(body))
	if err != nil {
		return Site{}, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return Site{}, fmt.Errorf("create the project: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusCreated {
		var out Site
		if err := decodeBody(resp.Body, &out); err != nil {
			return Site{}, fmt.Errorf("decode the new project: %w", err)
		}

		return out, nil
	}

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
	var payload struct {
		Error string `json:"error"`
	}
	_ = json.Unmarshal(detail, &payload)

	switch {
	case resp.StatusCode == http.StatusForbidden && payload.Error == "credential_not_owner_wide":
		return Site{}, ErrCredentialNotOwnerWide
	case resp.StatusCode == http.StatusConflict && payload.Error == "name_taken":
		return Site{}, ErrProjectNameTaken
	case resp.StatusCode == http.StatusUnprocessableEntity && payload.Error == "name_required":
		return Site{}, ErrProjectNameRequired
	case resp.StatusCode == http.StatusUnprocessableEntity && payload.Error == "name_invalid":
		return Site{}, ErrProjectNameInvalid
	case resp.StatusCode == http.StatusUnauthorized, resp.StatusCode == http.StatusForbidden:
		return Site{}, fmt.Errorf("create project rejected (HTTP %d): the API token must have the agent scope", resp.StatusCode)
	default:
		return Site{}, fmt.Errorf("create project failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(detail)))
	}
}
