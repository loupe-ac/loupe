package api

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
)

// GitHubRepositoryStatus asks how one repository, written as owner/name, stands
// for the project: in-project, free, other-project or not-shared. A server that
// predates the route answers 404, which comes back as an error.
func (c *Client) GitHubRepositoryStatus(ctx context.Context, handle, repository string) (string, error) {
	resp, err := c.Send(ctx, PublicRequest{
		Method: http.MethodGet,
		Path:   "/api/v1/projects/" + url.PathEscape(handle) + "/github/repositories/status",
		Query:  url.Values{"path": {repository}},
		Header: http.Header{"Accept": {"application/json"}},
	})
	if err != nil {
		return "", fmt.Errorf("look up the repository: %w", err)
	}
	if resp.Status != http.StatusOK {
		return "", fmt.Errorf("look up the repository: HTTP %d", resp.Status)
	}

	var answer struct {
		Status string `json:"status"`
	}
	if err := json.Unmarshal(resp.Body, &answer); err != nil || answer.Status == "" {
		return "", fmt.Errorf("read the repository status: the answer holds no status")
	}

	return answer.Status, nil
}
