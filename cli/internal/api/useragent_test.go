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

type fakeTransport struct{ calls int }

func (f *fakeTransport) RoundTrip(*http.Request) (*http.Response, error) {
	f.calls++

	return &http.Response{StatusCode: http.StatusOK, Body: http.NoBody}, nil
}

func TestUserAgentRefusesPlainHTTPToAnotherHostUnlessAllowed(t *testing.T) {
	for _, c := range []struct {
		url   string
		allow bool
		ok    bool
	}{
		{"http://loupe.lan/x", false, false},
		{"http://loupe.lan/x", true, true},
		{"https://loupe.lan/x", false, true},
		{"http://127.0.0.1:1/x", false, true},
	} {
		base := &fakeTransport{}
		req, _ := http.NewRequest(http.MethodGet, c.url, nil)
		resp, err := (UserAgent{Value: "ua", Base: base, AllowHTTP: c.allow}).RoundTrip(req)
		if (err == nil) != c.ok {
			t.Errorf("%s allow=%v: err = %v, want ok=%v", c.url, c.allow, err, c.ok)
		}
		if err == nil {
			_ = resp.Body.Close()
		} else if base.calls != 0 {
			t.Errorf("%s: a refused request reached the base transport", c.url)
		}
	}
}

// A server on https that redirects to plain HTTP on another host must not get
// the second request, unless the person opted in.
func TestUserAgentStopsARedirectFromHTTPSToPlainHTTP(t *testing.T) {
	plain := false
	target := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) { plain = true }))
	defer target.Close()
	secure := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "http://loupe.lan.invalid:1/", http.StatusFound)
	}))
	defer secure.Close()

	tr := secure.Client().Transport
	hc := &http.Client{Transport: UserAgent{Value: "ua", Base: tr}}
	if resp, err := hc.Get(secure.URL); err == nil {
		_ = resp.Body.Close()
		t.Fatal("the redirect to plain HTTP on another host went through")
	}
	if plain {
		t.Fatal("the plain server got a request")
	}
}
