package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// ErrGitIdentityNotManaged marks a 403 git_identity_not_managed, the answer for
// a project that keeps the git identity of its agents on the machine.
var ErrGitIdentityNotManaged = errors.New("the project does not manage the git identity of agents")

// ErrGitTokenUnsupported marks a 404 with no error code, which is the answer of
// a server that predates the git token route.
var ErrGitTokenUnsupported = errors.New("the server has no git token endpoint")

// gitTokenError turns a refused answer into an error. The cause is the error
// code the server gave, with the permissions it lists as missing.
func gitTokenError(status int, body []byte) error {
	var payload struct {
		Error   string   `json:"error"`
		Missing []string `json:"missing"`
	}
	_ = json.Unmarshal(body, &payload)

	switch {
	case status == http.StatusNotFound && payload.Error == "":
		return ErrGitTokenUnsupported
	case status == http.StatusForbidden && payload.Error == "git_identity_not_managed":
		return ErrGitIdentityNotManaged
	case payload.Error != "":
		cause := payload.Error
		if len(payload.Missing) > 0 {
			cause += " (" + strings.Join(payload.Missing, ", ") + ")"
		}

		return fmt.Errorf("the server refused to issue a git token (HTTP %d): %s", status, cause)
	default:
		return fmt.Errorf("the server refused to issue a git token (HTTP %d): %s", status, clip(strings.TrimSpace(string(body)), 200))
	}
}

// IssueGitToken asks for a GitHub App token for one repository, written as
// owner/name, of one project.
func (c *Client) IssueGitToken(ctx context.Context, handle, repository string) (string, time.Time, error) {
	body, err := json.Marshal(map[string]string{"repository": repository})
	if err != nil {
		return "", time.Time{}, fmt.Errorf("encode the git token request: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		c.baseURL+"/api/projects/"+url.PathEscape(handle)+"/git-token", bytes.NewReader(body))
	if err != nil {
		return "", time.Time{}, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return "", time.Time{}, fmt.Errorf("request a git token: %w", err)
	}
	defer resp.Body.Close()

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 64*1024))
	if resp.StatusCode != http.StatusCreated && resp.StatusCode != http.StatusOK {
		return "", time.Time{}, gitTokenError(resp.StatusCode, detail)
	}

	var answer struct {
		Token     string    `json:"token"`
		ExpiresAt time.Time `json:"expiresAt"`
	}
	if err := json.Unmarshal(detail, &answer); err != nil {
		return "", time.Time{}, fmt.Errorf("read the git token: %w", err)
	}
	if answer.Token == "" || answer.ExpiresAt.IsZero() {
		return "", time.Time{}, errors.New("read the git token: the answer holds no token or no expiry")
	}

	return answer.Token, answer.ExpiresAt, nil
}
