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

// ProjectHeader names the project a site-review request acts on. It takes a
// lower-case project id.
const ProjectHeader = "X-Loupe-Project"

// The answers the site-review routes give when the login cannot use them.
var (
	// ErrProjectNotCovered marks a token the server does not bind to the
	// project: the project is not the caller's, or the request named none.
	ErrProjectNotCovered = errors.New("this login does not cover the project")
	// ErrScopeMissing marks a token without the site-review scope.
	ErrScopeMissing = errors.New("this login lacks the site-review scope: sign in again")
)

// WithProject returns a copy of the client that sends the project id on the
// site-review methods. No other method sends the header.
func (c *Client) WithProject(id string) *Client {
	clone := *c
	clone.project = id

	return &clone
}

// siteReviewRequest sends one site-review request and returns the success body.
func (c *Client) siteReviewRequest(ctx context.Context, method, path string, payload []byte) (json.RawMessage, error) {
	var body io.Reader
	if payload != nil {
		body = bytes.NewReader(payload)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.baseURL+path, body)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")
	if payload != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if c.project != "" {
		req.Header.Set(ProjectHeader, c.project)
	}

	resp, err := c.do(req)
	if err != nil {
		return nil, fmt.Errorf("request %s: %w", path, err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK, http.StatusCreated:
		var out json.RawMessage
		if err := decodeBody(resp.Body, &out); err != nil {
			return nil, fmt.Errorf("decode %s: %w", path, err)
		}

		return out, nil
	case http.StatusUnauthorized:
		return nil, ErrScopeMissing
	case http.StatusForbidden:
		detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		var refusal struct {
			Error string `json:"error"`
		}
		_ = json.Unmarshal(detail, &refusal)
		if refusal.Error == "token_not_bound_to_site" {
			return nil, ErrProjectNotCovered
		}

		return nil, ErrScopeMissing
	default:
		detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return nil, fmt.Errorf("%s failed (HTTP %d): %s", path, resp.StatusCode, strings.TrimSpace(string(detail)))
	}
}

// SiteReviewTargets searches the open cards of the project. With parent set it
// lists only the cards that can hold children, which the epic mode picks from.
// It returns the `cards` list of the answer.
func (c *Client) SiteReviewTargets(ctx context.Context, query string, parent bool) (json.RawMessage, error) {
	params := url.Values{}
	if query != "" {
		params.Set("q", query)
	}
	if parent {
		params.Set("parent", "1")
	}
	path := "/api/board/cards"
	if len(params) > 0 {
		path += "?" + params.Encode()
	}
	raw, err := c.siteReviewRequest(ctx, http.MethodGet, path, nil)
	if err != nil {
		return nil, err
	}

	return field(raw, "cards")
}

// SiteReviewNotes lists the pending iOS Simulator notes of one destination,
// such as `card:<id>`. The server lists every note of the project when the
// scope is absent, so the request always sends it, and an empty scope lists
// nothing. It returns the answer as the server sent it.
func (c *Client) SiteReviewNotes(ctx context.Context, scope string) (json.RawMessage, error) {
	params := url.Values{"surface": {"ios-simulator"}, "scope": {scope}}

	return c.siteReviewRequest(ctx, http.MethodGet, "/api/live-review/review?"+params.Encode(), nil)
}

// SaveSiteReviewNote posts one feedback request body and returns the answer.
func (c *Client) SaveSiteReviewNote(ctx context.Context, body []byte) (json.RawMessage, error) {
	return c.siteReviewRequest(ctx, http.MethodPost, "/api/board/feedback", body)
}

// field returns one top-level key of a JSON object.
func field(raw json.RawMessage, key string) (json.RawMessage, error) {
	var object map[string]json.RawMessage
	if err := json.Unmarshal(raw, &object); err != nil {
		return nil, fmt.Errorf("decode the answer: %w", err)
	}
	value, ok := object[key]
	if !ok {
		return nil, fmt.Errorf("the answer has no %q key", key)
	}

	return value, nil
}
