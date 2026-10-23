package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
)

// CreateProjectRequest is the body of POST /api/projects. The server wants a
// name, a remote, or both, and it names a project after its remote when the
// name is empty. An empty field is left out of the body.
type CreateProjectRequest struct {
	Name    string   `json:"name,omitempty"`
	Domain  string   `json:"domain,omitempty"`
	Origins []string `json:"origins,omitempty"`
	Remote  string   `json:"remote,omitempty"`
}

// NameTakenError is the answer to a name or slug that another project of the
// owner has. Slug names that project, and is empty when the server found none.
type NameTakenError struct{ Slug string }

func (e *NameTakenError) Error() string {
	if e.Slug == "" {
		return ErrProjectNameTaken.Error()
	}

	return fmt.Sprintf("%s (your project %q has this name)", ErrProjectNameTaken, e.Slug)
}

// Is makes errors.Is(err, ErrProjectNameTaken) true for this error.
func (e *NameTakenError) Is(target error) bool { return target == ErrProjectNameTaken }

// InvalidValue is one value that the server refused. Field is the flag that
// held it, such as --origin.
type InvalidValue struct{ Field, Value, Message string }

// InvalidValuesError is the 422 answer that lists each refused value. The
// server creates no project when it sends this.
type InvalidValuesError struct{ Values []InvalidValue }

func (e *InvalidValuesError) Error() string {
	lines := make([]string, 0, len(e.Values))
	for _, v := range e.Values {
		if v.Value == "" {
			lines = append(lines, fmt.Sprintf("%s: %s", v.Field, v.Message))

			continue
		}
		lines = append(lines, fmt.Sprintf("%s %q: %s", v.Field, v.Value, v.Message))
	}

	return "the server refused these values, and created no project:\n  " + strings.Join(lines, "\n  ")
}

// maxProblemBody bounds how much of an error answer CreateProject reads. A
// 422 lists one violation per origin, so 512 bytes is too small for it.
const maxProblemBody = 64 << 10

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

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, maxProblemBody))
	var payload struct {
		Error      string `json:"error"`
		Slug       string `json:"slug"`
		Violations []struct {
			PropertyPath string `json:"propertyPath"`
			Title        string `json:"title"`
		} `json:"violations"`
	}
	_ = json.Unmarshal(detail, &payload)

	if resp.StatusCode == http.StatusUnprocessableEntity && len(payload.Violations) > 0 {
		values := make([]InvalidValue, 0, len(payload.Violations))
		for _, v := range payload.Violations {
			field, value := flagOf(v.PropertyPath, in)
			values = append(values, InvalidValue{Field: field, Value: value, Message: v.Title})
		}

		return Site{}, &InvalidValuesError{Values: values}
	}

	switch {
	case resp.StatusCode == http.StatusForbidden && payload.Error == "credential_not_owner_wide":
		return Site{}, ErrCredentialNotOwnerWide
	case resp.StatusCode == http.StatusConflict && payload.Error == "name_taken":
		return Site{}, &NameTakenError{Slug: payload.Slug}
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

// flagOf maps the property path of a violation to the flag that held the value
// and to the value. A path it does not know comes back as it is, with no value.
func flagOf(path string, in CreateProjectRequest) (field, value string) {
	switch {
	case path == "domain":
		return "--domain", in.Domain
	case path == "name":
		return "--name", in.Name
	case path == "origins":
		return "--origin", strings.Join(in.Origins, " ")
	case strings.HasPrefix(path, "origins[") && strings.HasSuffix(path, "]"):
		if i, err := strconv.Atoi(path[len("origins[") : len(path)-1]); err == nil && i >= 0 && i < len(in.Origins) {
			return "--origin", in.Origins[i]
		}
	}

	return path, ""
}
