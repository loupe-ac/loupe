package config

import "testing"

func TestCheckServerURL(t *testing.T) {
	cases := []struct {
		url   string
		allow bool
		ok    bool
	}{
		{"https://app.loupe.ac", false, true},
		{"https://192.168.1.10", false, true},
		{"http://localhost", false, true},
		{"http://localhost:9000", false, true},
		{"http://LOCALHOST.", false, true},
		{"http://card-1.loupe.dev.localhost", false, true},
		{"http://127.0.0.1:8080", false, true},
		{"http://127.5.5.5", false, true},
		{"http://[::1]:8080", false, true},
		{"http://192.168.1.10", false, false},
		{"http://loupe.lan", false, false},
		{"http://notlocalhost", false, false},
		{"http://localhost.example.com", false, false},
		{"http://192.168.1.10", true, true},
		{"loupe.example.com", false, false},
		{"ftp://loupe.example.com", true, false},
		{"", false, false},
		{"https://", false, false},
	}
	for _, c := range cases {
		err := CheckServerURL(c.url, c.allow)
		if (err == nil) != c.ok {
			t.Errorf("CheckServerURL(%q, %v) = %v, want ok=%v", c.url, c.allow, err, c.ok)
		}
	}
}

func TestAllowsHTTPReadsTheFieldAndTheEnvironment(t *testing.T) {
	t.Setenv(AllowHTTPEnv, "")
	if (Config{}).AllowsHTTP() {
		t.Fatal("a blank config must not allow plain HTTP")
	}
	if !(Config{AllowHTTP: true}).AllowsHTTP() {
		t.Fatal("the stored field must allow it")
	}
	t.Setenv(AllowHTTPEnv, "1")
	if !(Config{}).AllowsHTTP() {
		t.Fatal("LOUPE_ALLOW_HTTP=1 must allow it")
	}
}
