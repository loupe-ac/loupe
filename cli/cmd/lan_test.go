package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// lanTransport sends every request to one test server and keeps the host the
// caller named, so a test can use a plain-HTTP address such as http://loupe.lan
// with no network.
type lanTransport struct {
	target string
	base   http.RoundTripper
}

func (l lanTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	req = req.Clone(req.Context())
	req.URL.Host = l.target

	return l.base.RoundTrip(req)
}

// routeLAN sends each request that has no transport of its own to srv.
func routeLAN(t *testing.T, srv *httptest.Server) {
	t.Helper()
	previous := http.DefaultTransport
	http.DefaultTransport = lanTransport{target: strings.TrimPrefix(srv.URL, "http://"), base: previous}
	t.Cleanup(func() { http.DefaultTransport = previous })
}
