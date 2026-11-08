package cmd

import (
	"fmt"
	"os"
	"testing"
)

func TestMain(m *testing.M) {
	if os.Getenv("LOUPE_TEST_AS_CLI") != "" {
		// A test installs this binary as the loupe that a worker calls.
		if err := Execute(); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		os.Exit(0)
	}
	os.Unsetenv("LOUPE_CONFIG")
	os.Exit(m.Run())
}
