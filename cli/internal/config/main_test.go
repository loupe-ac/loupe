package config

import (
	"os"
	"testing"
)

func TestMain(m *testing.M) {
	os.Unsetenv("LOUPE_CONFIG")
	os.Exit(m.Run())
}
