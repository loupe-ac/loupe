package api

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
)

// RunOutputAnswer is what the server holds of a run's output after a chunk.
// Length is the byte length it stores. Capped says it stores no more.
type RunOutputAnswer struct {
	Length int64 `json:"length"`
	Capped bool  `json:"capped"`
}

// PutRunOutput sends one chunk of a run's output, as PUT
// /api/projects/{handle}/worker-runs/{runId}/output takes it. The server
// appends text only when offset equals the length it stores. Any other offset
// stores nothing, and the answer holds the stored length.
func (c *Client) PutRunOutput(ctx context.Context, handle, runID, bridgeID string, offset int64, text string) (RunOutputAnswer, error) {
	var answer RunOutputAnswer
	body, err := json.Marshal(struct {
		BridgeID string `json:"bridgeId"`
		Offset   int64  `json:"offset"`
		Text     string `json:"text"`
	}{bridgeID, offset, text})
	if err != nil {
		return answer, fmt.Errorf("%w: encode the run output: %w", ErrReportRefused, err)
	}

	status, detail, err := c.putReport(ctx,
		"/api/projects/"+url.PathEscape(handle)+"/worker-runs/"+url.PathEscape(runID)+"/output", body)
	if err != nil {
		return answer, fmt.Errorf("send the run output: %w", err)
	}
	if status != http.StatusOK {
		return answer, reportFailure("run output", status, detail)
	}
	if err := json.Unmarshal(detail, &answer); err != nil {
		return RunOutputAnswer{}, fmt.Errorf("read the run output answer: %w", err)
	}

	return answer, nil
}
