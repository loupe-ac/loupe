package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"
)

// pendingSignInFileName holds the device code that `loupe review sign-in`
// started, until `loupe review status` spends it.
const pendingSignInFileName = "review-sign-in.json"

// PendingSignIn is a device sign-in that waits for approval. DeviceCode is a
// secret, so the file is written at 0600 like config.json.
type PendingSignIn struct {
	BaseURL    string    `json:"baseUrl"`
	DeviceCode string    `json:"deviceCode"`
	Interval   int       `json:"interval"`
	ExpiresAt  time.Time `json:"expiresAt"`
	NextPollAt time.Time `json:"nextPollAt"`
}

// LoadPendingSignIn gives the waiting sign-in, or nil when none waits.
func LoadPendingSignIn() (*PendingSignIn, error) {
	d, err := Dir()
	if err != nil {
		return nil, err
	}
	b, err := os.ReadFile(filepath.Join(d, pendingSignInFileName))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, fmt.Errorf("read the pending sign-in: %w", err)
	}
	var p PendingSignIn
	if err := json.Unmarshal(b, &p); err != nil || p.DeviceCode == "" {
		// A file this cannot read holds no sign-in to finish.
		return nil, ClearPendingSignIn()
	}

	return &p, nil
}

// SavePendingSignIn replaces the waiting sign-in.
func SavePendingSignIn(p PendingSignIn) error {
	d, err := Dir()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(d, 0o700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}
	b, err := json.Marshal(p)
	if err != nil {
		return err
	}
	f, err := os.CreateTemp(d, pendingSignInFileName+".*")
	if err != nil {
		return fmt.Errorf("write the pending sign-in: %w", err)
	}
	tmp := f.Name()
	defer os.Remove(tmp)
	if _, err := f.Write(b); err != nil {
		f.Close()

		return fmt.Errorf("write the pending sign-in: %w", err)
	}
	if err := f.Close(); err != nil {
		return fmt.Errorf("write the pending sign-in: %w", err)
	}
	if err := os.Rename(tmp, filepath.Join(d, pendingSignInFileName)); err != nil {
		return fmt.Errorf("write the pending sign-in: %w", err)
	}

	return nil
}

// ClearPendingSignIn removes the waiting sign-in, when there is one.
func ClearPendingSignIn() error {
	d, err := Dir()
	if err != nil {
		return err
	}
	if err := os.Remove(filepath.Join(d, pendingSignInFileName)); err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("remove the pending sign-in: %w", err)
	}

	return nil
}
