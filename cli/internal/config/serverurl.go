package config

import (
	"fmt"
	"net"
	"net/url"
	"os"
	"strings"
)

// AllowHTTPEnv is the environment variable that lets one command send tokens
// over plain HTTP to a host that is not the same machine.
const AllowHTTPEnv = "LOUPE_ALLOW_HTTP"

// AllowHTTPFromEnv reports whether LOUPE_ALLOW_HTTP=1 is set.
func AllowHTTPFromEnv() bool {
	return os.Getenv(AllowHTTPEnv) == "1"
}

// AllowsHTTP reports whether this login may use plain HTTP to another machine.
func (c Config) AllowsHTTP() bool {
	return c.AllowHTTP || AllowHTTPFromEnv()
}

// CheckServerURL refuses an address that would send a token without TLS.
// https is always allowed. http is allowed to the same machine (localhost, a
// name that ends in .localhost, or a loopback IP), and to any host when the
// person opted in with allowHTTP. Any other scheme is refused.
func CheckServerURL(raw string, allowHTTP bool) error {
	u, err := url.Parse(strings.TrimSpace(raw))
	if err != nil || u.Host == "" {
		return fmt.Errorf("%q is not a server address: start it with https://", raw)
	}

	switch strings.ToLower(u.Scheme) {
	case "https":
		return nil
	case "http":
		if allowHTTP || isLoopbackHost(u.Hostname()) {
			return nil
		}

		return fmt.Errorf("%s sends your tokens unencrypted: use https://, or pass --allow-http (or set %s=1) if this network is trusted", raw, AllowHTTPEnv)
	default:
		return fmt.Errorf("%q has no usable scheme: start it with https://", raw)
	}
}

func isLoopbackHost(host string) bool {
	host = strings.TrimSuffix(strings.ToLower(host), ".")
	if host == "localhost" || strings.HasSuffix(host, ".localhost") {
		return true
	}
	ip := net.ParseIP(host)

	return ip != nil && ip.IsLoopback()
}
