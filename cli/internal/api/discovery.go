package api

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strings"
)

// Discovery is the answer of POST /api/v1/projects/{project}/discovery. The
// parser is tolerant: a field the server omits stays at its zero value, and
// the answer may sit under a top-level data key.
type Discovery struct {
	RunID      string
	CardNumber int
	CardID     string
	// ReportURL is the address of the report, when the server sends one.
	ReportURL string
}

// StartDiscovery asks the server to start a discovery run for the project,
// given by id or slug.
func (c *Client) StartDiscovery(ctx context.Context, project string) (Discovery, error) {
	resp, err := c.Send(ctx, PublicRequest{
		Method: http.MethodPost,
		Path:   "/api/v1/projects/" + url.PathEscape(project) + "/discovery",
	})
	if err != nil {
		return Discovery{}, err
	}
	if resp.Status >= 400 {
		return Discovery{}, fmt.Errorf("start discovery: HTTP %d%s", resp.Status, problemText(resp.Body))
	}

	return parseDiscovery(resp.Body), nil
}

// problemText reads the title and the detail of a Problem JSON body.
func problemText(body []byte) string {
	var p struct {
		Title  string `json:"title"`
		Detail string `json:"detail"`
	}
	if json.Unmarshal(body, &p) != nil {
		return ""
	}
	text := strings.TrimSpace(p.Title + " " + p.Detail)
	if text == "" {
		return ""
	}

	return ": " + text
}

func parseDiscovery(body []byte) Discovery {
	var raw map[string]json.RawMessage
	if json.Unmarshal(body, &raw) != nil {
		return Discovery{}
	}
	if data, ok := raw["data"]; ok {
		var inner map[string]json.RawMessage
		if json.Unmarshal(data, &inner) == nil {
			raw = inner
		}
	}

	str := func(key string) string {
		var s string
		if json.Unmarshal(raw[key], &s) == nil {
			return s
		}

		return ""
	}
	d := Discovery{RunID: str("runId"), CardID: str("cardId"), ReportURL: str("reportUrl")}
	if d.ReportURL == "" {
		d.ReportURL = str("url")
	}
	var n int
	if json.Unmarshal(raw["cardNumber"], &n) == nil {
		d.CardNumber = n
	}

	return d
}
