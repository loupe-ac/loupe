package api

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
)

// CloseInteractiveSession tells Loupe that an interactive session ended, as
// POST /api/interactive-sessions/{sessionId}/close takes it. It answers how
// many open runs the server closed, which is 0 for a session it does not know.
func (c *Client) CloseInteractiveSession(ctx context.Context, sessionID string) (int, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		c.baseURL+"/api/interactive-sessions/"+url.PathEscape(sessionID)+"/close", nil)
	if err != nil {
		return 0, err
	}
	req.Header.Set("Accept", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return 0, fmt.Errorf("close the interactive session: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))

		return 0, fmt.Errorf("close the interactive session failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(detail)))
	}

	var answer struct {
		Closed int `json:"closed"`
	}
	if err := decodeBody(resp.Body, &answer); err != nil {
		return 0, fmt.Errorf("read the close answer: %w", err)
	}

	return answer.Closed, nil
}
