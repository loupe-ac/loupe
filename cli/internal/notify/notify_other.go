//go:build !darwin

package notify

import "context"

// Send does nothing on this system.
func Send(context.Context, string, string) error { return nil }
