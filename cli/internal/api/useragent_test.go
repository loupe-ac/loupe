package api

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestUserAgentSetsTheHeaderWithoutChangingTheCallerRequest(t *testing.T) {
	var got string
	srv := httptest.NewServer(http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		got = r.Header.Get("User-Agent")
	}))
	defer srv.Close()

	req, err := http.NewRequest(http.MethodGet, srv.URL, nil)
	if err != nil {
		t.Fatal(err)
	}
	resp, err := (&http.Client{Transport: UserAgent{Value: "loupe-cli/1.2.3"}}).Do(req)
	if err != nil {
		t.Fatal(err)
	}
	_ = resp.Body.Close()

	if got != "loupe-cli/1.2.3" {
		t.Fatalf("User-Agent = %q", got)
	}
	if req.Header.Get("User-Agent") != "" {
		t.Fatal("the caller request was changed")
	}
}
