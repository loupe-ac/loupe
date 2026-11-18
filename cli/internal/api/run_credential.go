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
)

// ErrRunCredentialUnsupported marks a 404 with no error code, which is the
// answer of a server that predates the run credential route.
var ErrRunCredentialUnsupported = errors.New("the server has no run credential endpoint")

// IssueRunCredential asks for the run token of one worker launch. runKey is the
// key the bridge gave the run, and handle is the project id.
func (c *Client) IssueRunCredential(ctx context.Context, handle, runKey, bridgeID, sessionID string) (string, error) {
	body, err := json.Marshal(map[string]string{"bridgeId": bridgeID, "sessionId": sessionID})
	if err != nil {
		return "", fmt.Errorf("encode the run credential request: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		c.baseURL+"/api/v1/bridge/projects/"+url.PathEscape(handle)+"/worker-runs/"+url.PathEscape(runKey)+"/credential", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return "", fmt.Errorf("request a run credential: %w", err)
	}
	defer resp.Body.Close()

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 64*1024))
	if resp.StatusCode != http.StatusCreated && resp.StatusCode != http.StatusOK {
		var payload struct {
			Code string `json:"code"`
		}
		_ = json.Unmarshal(detail, &payload)
		if resp.StatusCode == http.StatusNotFound && routeMissing(payload.Code) {
			return "", ErrRunCredentialUnsupported
		}
		if payload.Code != "" {
			return "", fmt.Errorf("the server refused to issue a run credential (HTTP %d): %s", resp.StatusCode, payload.Code)
		}

		return "", fmt.Errorf("the server refused to issue a run credential (HTTP %d): %s", resp.StatusCode, clip(strings.TrimSpace(string(detail)), 200))
	}

	var answer struct {
		Token string `json:"token"`
	}
	if err := json.Unmarshal(detail, &answer); err != nil {
		return "", fmt.Errorf("read the run credential: %w", err)
	}
	if answer.Token == "" {
		return "", errors.New("read the run credential: the answer holds no token")
	}

	return answer.Token, nil
}
