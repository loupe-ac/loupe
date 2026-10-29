package config

import (
	"errors"
	"os"
	"time"
)

// refreshRetryDelays spaces the retries of a refresh whose answer never
// arrived. A var, so a test can shorten it.
var refreshRetryDelays = []time.Duration{2 * time.Second, 5 * time.Second}

type transientError struct{ err error }

func (e transientError) Error() string { return e.err.Error() }
func (e transientError) Unwrap() error { return e.err }

// Transient marks err as a transport failure: the request may have reached the
// server, and no HTTP answer came back.
func Transient(err error) error {
	if err == nil {
		return nil
	}

	return transientError{err}
}

// IsTransient reports whether err carries the Transient mark.
func IsTransient(err error) bool {
	var t transientError

	return errors.As(err, &t)
}

// RotateOAuth gives a usable access token for the device login of baseURL.
// stale is the access token the caller holds and can no longer use.
//
// Refresh tokens rotate, and the server refuses a used one. Several processes
// can share this file, so the read, the refresh and the write all happen under
// the config lock, after a fresh read. When the file already holds another
// access token that is valid past validUntil, another process has refreshed,
// and this call returns that token with no refresh. Otherwise refresh gets the
// stored refresh token, and its answer replaces the stored login.
func RotateOAuth(baseURL, stale string, validUntil time.Time, refresh func(refreshToken string) (OAuthTokens, error)) (OAuthTokens, error) {
	var out OAuthTokens
	d, err := Dir()
	if err != nil {
		return out, err
	}
	if _, err := os.Stat(d); errors.Is(err, os.ErrNotExist) {
		return out, ErrNotLoggedIn
	}

	err = withConfigLock(d, func() error {
		c, err := readStoredConfig(d)
		if errors.Is(err, os.ErrNotExist) {
			return ErrNotLoggedIn
		}
		if err != nil {
			return err
		}
		if c.BaseURL != baseURL || c.OAuth == nil || c.OAuth.RefreshToken == "" {
			return ErrNotLoggedIn
		}

		if c.OAuth.AccessToken != stale && c.OAuth.ExpiresAt.After(validUntil) {
			out = *c.OAuth

			return nil
		}

		fresh, err := refresh(c.OAuth.RefreshToken)
		// A transport failure can hide a rotation that the server finished.
		// The server accepts the same refresh token again until its successor
		// is used, so retry with it at once. An HTTP answer is never retried.
		for _, delay := range refreshRetryDelays {
			if err == nil || !IsTransient(err) {
				break
			}
			time.Sleep(delay)
			fresh, err = refresh(c.OAuth.RefreshToken)
		}
		if err != nil {
			return err
		}
		c.OAuth = &fresh
		if err := writeConfig(d, c); err != nil {
			return err
		}
		out = fresh

		return nil
	})

	return out, err
}
