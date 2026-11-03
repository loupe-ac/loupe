package api

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
)

// MaxPublicBody caps an answer of the public API. The bridge limit of 1 MiB
// would refuse a document of that size, so the public commands read more.
const MaxPublicBody = 16 << 20

// ErrPublicBodyTooLarge marks an answer above MaxPublicBody.
var ErrPublicBodyTooLarge = errors.New("the answer is larger than 16 MiB")

// PublicRequest is one request to the public API. Path starts with /api/ and
// holds every path value already escaped.
type PublicRequest struct {
	Method string
	Path   string
	Query  url.Values
	Body   []byte
	Header http.Header
}

// PublicResponse is the whole answer to a PublicRequest, of any status.
type PublicResponse struct {
	Status int
	Header http.Header
	Body   []byte
}

// BaseURL gives the instance address the client sends to.
func (c *Client) BaseURL() string { return c.baseURL }

// Send sends r through do, so a login still refreshes and the retired host
// check still runs. A status of 400 or more is an answer, never an error.
func (c *Client) Send(ctx context.Context, r PublicRequest) (*PublicResponse, error) {
	target := c.baseURL + r.Path
	if len(r.Query) > 0 {
		target += "?" + r.Query.Encode()
	}

	var body io.Reader
	if r.Body != nil {
		body = bytes.NewReader(r.Body)
	}
	req, err := http.NewRequestWithContext(ctx, r.Method, target, body)
	if err != nil {
		return nil, err
	}
	for name, values := range r.Header {
		req.Header[name] = append([]string(nil), values...)
	}
	if r.Body != nil && req.Header.Get("Content-Type") == "" {
		req.Header.Set("Content-Type", "application/json")
	}

	resp, err := c.do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	data, err := io.ReadAll(io.LimitReader(resp.Body, MaxPublicBody+1))
	if err != nil {
		return nil, fmt.Errorf("read the answer: %w", err)
	}
	if len(data) > MaxPublicBody {
		return nil, ErrPublicBodyTooLarge
	}

	return &PublicResponse{Status: resp.StatusCode, Header: resp.Header, Body: data}, nil
}
