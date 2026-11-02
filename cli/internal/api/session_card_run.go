package api

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
)

// CardRun is what Loupe knows about the card run of an interactive session in
// one project. Template is the workflow template key of the project, and ""
// when the server names none. Open reports an open card run for the session.
type CardRun struct {
	Template string `json:"template"`
	Open     bool   `json:"openCardRun"`
}

// SessionCardRun reads GET /api/interactive-sessions/{sessionId}/card-run for
// the project. Any answer but 200 is an error, because the caller cannot tell
// the state from it.
func (c *Client) SessionCardRun(ctx context.Context, sessionID, projectID string) (CardRun, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet,
		c.baseURL+"/api/interactive-sessions/"+url.PathEscape(sessionID)+"/card-run", nil)
	if err != nil {
		return CardRun{}, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set(ProjectHeader, projectID)

	resp, err := c.do(req)
	if err != nil {
		return CardRun{}, fmt.Errorf("read the session card run: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))

		return CardRun{}, fmt.Errorf("read the session card run failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(detail)))
	}

	var answer CardRun
	if err := decodeBody(resp.Body, &answer); err != nil {
		return CardRun{}, fmt.Errorf("read the card run answer: %w", err)
	}

	return answer, nil
}
