package api

import "net/http"

// UserAgent sets the User-Agent of each request it sends, so a request log
// names the build of the caller.
type UserAgent struct {
	Value string
	// Base is the transport that sends the request. Nil means http.DefaultTransport.
	Base http.RoundTripper
}

func (u UserAgent) RoundTrip(req *http.Request) (*http.Response, error) {
	base := u.Base
	if base == nil {
		base = http.DefaultTransport
	}

	req = req.Clone(req.Context())
	req.Header.Set("User-Agent", u.Value)

	return base.RoundTrip(req)
}
