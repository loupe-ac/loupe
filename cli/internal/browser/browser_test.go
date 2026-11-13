package browser

import (
	"reflect"
	"testing"
)

func TestCommandPerSystem(t *testing.T) {
	cases := map[string][]string{
		"darwin":  {"open", "https://x.test"},
		"linux":   {"xdg-open", "https://x.test"},
		"windows": {"rundll32", "url.dll,FileProtocolHandler", "https://x.test"},
		"freebsd": {"xdg-open", "https://x.test"},
	}
	for goos, want := range cases {
		name, args := command(goos, "https://x.test")
		if got := append([]string{name}, args...); !reflect.DeepEqual(got, want) {
			t.Errorf("%s: got %v, want %v", goos, got, want)
		}
	}
}

func TestOpenRunsTheCommandOfThisSystem(t *testing.T) {
	var got []string
	old := start
	start = func(name string, args ...string) error {
		got = append([]string{name}, args...)

		return nil
	}
	t.Cleanup(func() { start = old })

	if err := Open("https://x.test"); err != nil {
		t.Fatal(err)
	}
	if len(got) < 2 || got[len(got)-1] != "https://x.test" {
		t.Errorf("got %v", got)
	}
}
