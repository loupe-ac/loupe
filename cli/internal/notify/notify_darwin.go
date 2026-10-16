package notify

import (
	"context"
	"os/exec"
	"time"
)

const timeout = 5 * time.Second

// Send shows a macOS notice. Callers pass constant text, because the text goes
// into an AppleScript string. A failure is returned and never fatal.
func Send(ctx context.Context, title, message string) error {
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	script := `display notification "` + message + `" with title "` + title + `"`

	return exec.CommandContext(ctx, "/usr/bin/osascript", "-e", script).Run()
}
