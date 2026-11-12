// Package browser opens a link in the default browser of the machine.
package browser

import (
	"os/exec"
	"runtime"
)

// start runs the program. A test replaces it.
var start = func(name string, args ...string) error {
	return exec.Command(name, args...).Start()
}

// Open starts the opener of the operating system for link. It does not wait for
// the browser. A caller that prints the link as well may ignore the error.
func Open(link string) error {
	name, args := command(runtime.GOOS, link)

	return start(name, args...)
}

func command(goos, link string) (string, []string) {
	switch goos {
	case "darwin":
		return "open", []string{link}
	case "windows":
		return "rundll32", []string{"url.dll,FileProtocolHandler", link}
	default:
		return "xdg-open", []string{link}
	}
}
