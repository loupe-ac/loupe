package api

import (
	"net/http"

	"github.com/loupe-ac/loupe/cli/internal/config"
)

// UserAgent sets the User-Agent of each request it sends, so a request log
// names the build of the caller.
type UserAgent struct {
	Value string
	// Base is the transport that sends the request. Nil means http.DefaultTransport.
	Base http.RoundTripper
	// AllowHTTP lets a request go over plain HTTP to another machine. Without
	// it, a request to such an address, a redirect step included, fails before
	// it leaves, so no token crosses the network unencrypted.
	AllowHTTP bool
}

func (u UserAgent) RoundTrip(req *http.Request) (*http.Response, error) {
	if err := config.CheckServerURL(req.URL.String(), u.AllowHTTP); err != nil {
		return nil, err
	}

	base := u.Base
	if base == nil {
		base = http.DefaultTransport
	}

	req = req.Clone(req.Context())
	req.Header.Set("User-Agent", u.Value)

	return base.RoundTrip(req)
}
