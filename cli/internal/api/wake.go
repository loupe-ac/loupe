package api

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
)

// ReportSessionWakes sends the wake of each worker process of a session, in
// the order the processes started. A nil wake says the process has none. Loupe
// fills the wake of a run that holds none and leaves the others, and answers
// 204 either way. A session it does not know answers 404.
func (c *Client) ReportSessionWakes(ctx context.Context, handle, sessionID string, wakes []*Wake) error {
	body, err := json.Marshal(struct {
		Wakes []*Wake `json:"wakes"`
	}{wakes})
	if err != nil {
		return fmt.Errorf("%w: encode the session wakes: %w", ErrReportRefused, err)
	}

	status, detail, err := c.putReport(ctx,
		"/api/v1/bridge/projects/"+url.PathEscape(handle)+"/worker-runs/sessions/"+url.PathEscape(sessionID)+"/wake", body)
	if err != nil {
		return fmt.Errorf("report the session wakes: %w", err)
	}
	switch status {
	case http.StatusNoContent, http.StatusOK:
		return nil
	}

	return reportFailure("session wakes", status, detail)
}
