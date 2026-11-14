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
// POST /api/v1/bridge/interactive-sessions/{sessionId}/close takes it. It answers how
// many open runs the server closed, which is 0 for a session it does not know.
// A true finished sends {"finished": true}, which says the work ended well.
// A false one sends no body.
func (c *Client) CloseInteractiveSession(ctx context.Context, sessionID string, finished bool) (int, error) {
	var body io.Reader
	if finished {
		body = strings.NewReader(`{"finished":true}`)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		c.baseURL+"/api/v1/bridge/interactive-sessions/"+url.PathEscape(sessionID)+"/close", body)
	if err != nil {
		return 0, err
	}
	req.Header.Set("Accept", "application/json")
	if finished {
		req.Header.Set("Content-Type", "application/json")
	}

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
