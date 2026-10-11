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

// ErrPreviewTokensUnsupported marks a 404 with no error code, which is the
// answer of a server that predates the preview token routes.
var ErrPreviewTokensUnsupported = errors.New("the server has no preview token endpoint")

// previewTokenError turns a refused answer into one line for a person.
func previewTokenError(what string, status int, body []byte) error {
	var payload struct {
		Error string `json:"error"`
	}
	_ = json.Unmarshal(body, &payload)

	switch {
	case status == http.StatusNotFound && payload.Error == "":
		return ErrPreviewTokensUnsupported
	case status == http.StatusNotFound && payload.Error == "project_not_found":
		return ErrProjectNotFound
	case status == http.StatusUnauthorized, status == http.StatusForbidden:
		return fmt.Errorf("%s was rejected (HTTP %d): sign in again with `loupe login`", what, status)
	case payload.Error != "":
		return fmt.Errorf("the server refused to %s (HTTP %d): %s", what, status, payload.Error)
	default:
		return fmt.Errorf("the server refused to %s (HTTP %d): %s", what, status, clip(strings.TrimSpace(string(body)), 200))
	}
}

// MintPreviewToken asks for a preview token of one project. A second mint
// with the same label replaces the first token. cardID may be empty.
func (c *Client) MintPreviewToken(ctx context.Context, handle, label, cardID string) (string, error) {
	payload := map[string]string{"label": label}
	if cardID != "" {
		payload["card"] = cardID
	}
	body, err := json.Marshal(payload)
	if err != nil {
		return "", fmt.Errorf("encode the preview token request: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		c.baseURL+"/api/projects/"+url.PathEscape(handle)+"/preview-tokens", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return "", fmt.Errorf("request a preview token: %w", err)
	}
	defer resp.Body.Close()

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 64*1024))
	if resp.StatusCode != http.StatusCreated && resp.StatusCode != http.StatusOK {
		return "", previewTokenError("mint a preview token", resp.StatusCode, detail)
	}

	var answer struct {
		Token string `json:"token"`
	}
	if err := json.Unmarshal(detail, &answer); err != nil {
		return "", fmt.Errorf("read the preview token: %w", err)
	}
	if answer.Token == "" {
		return "", errors.New("read the preview token: the answer holds no token")
	}

	return answer.Token, nil
}

// RevokePreviewTokens revokes the live preview tokens of one label. It
// answers how many it revoked, and zero is not an error.
func (c *Client) RevokePreviewTokens(ctx context.Context, handle, label string) (int, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodDelete,
		c.baseURL+"/api/projects/"+url.PathEscape(handle)+"/preview-tokens/"+url.PathEscape(label), nil)
	if err != nil {
		return 0, err
	}
	req.Header.Set("Accept", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return 0, fmt.Errorf("revoke preview tokens: %w", err)
	}
	defer resp.Body.Close()

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 64*1024))
	if resp.StatusCode != http.StatusOK {
		return 0, previewTokenError("revoke preview tokens", resp.StatusCode, detail)
	}

	var answer struct {
		Revoked int `json:"revoked"`
	}
	if err := json.Unmarshal(detail, &answer); err != nil {
		return 0, fmt.Errorf("read the revoke answer: %w", err)
	}

	return answer.Revoked, nil
}
