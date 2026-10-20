package api

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestAnHTMLNotFoundMeansTheInstanceAddressDoesNotAnswer(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/html; charset=UTF-8")
		w.WriteHeader(http.StatusNotFound)
		_, _ = io.WriteString(w, "<html>Not found</html>")
	}))
	defer server.Close()

	client := New("https://loupe.ac", "t", &http.Client{Transport: redirectTo(server)})
	calls := map[string]func() error{
		"heartbeat": func() error {
			_, err := client.Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v"})
			return err
		},
		"events": func() error { _, err := client.Events(context.Background(), "b"); return err },
	}
	for name, call := range calls {
		err := call()
		if !errors.Is(err, ErrInstanceUnanswered) {
			t.Fatalf("%s: err = %v", name, err)
		}
		for _, want := range []string{"https://loupe.ac", "does not answer", "loupe login --url"} {
			if !strings.Contains(err.Error(), want) {
				t.Fatalf("%s: message %q lacks %q", name, err, want)
			}
		}
	}
}

func TestAJSONNotFoundStaysAnAnswerOfTheInstance(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusNotFound)
		_, _ = io.WriteString(w, `{"error":"card_not_found"}`)
	}))
	defer server.Close()

	_, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v"})
	if !errors.Is(err, ErrHeartbeatMissing) || errors.Is(err, ErrInstanceUnanswered) {
		t.Fatalf("err = %v", err)
	}
}

func TestAnHTMLNotFoundFromAnotherHostIsNotTheRetiredAddress(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/html")
		w.WriteHeader(http.StatusNotFound)
	}))
	defer server.Close()

	_, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v"})
	if errors.Is(err, ErrInstanceUnanswered) {
		t.Fatalf("err = %v", err)
	}
}

// redirectTo sends every request to server, whatever its address.
func redirectTo(server *httptest.Server) http.RoundTripper {
	return roundTripFunc(func(req *http.Request) (*http.Response, error) {
		moved := req.Clone(req.Context())
		moved.URL.Scheme = "http"
		moved.URL.Host = strings.TrimPrefix(server.URL, "http://")

		resp, err := http.DefaultTransport.RoundTrip(moved)
		if resp != nil {
			resp.Request = req
		}

		return resp, err
	})
}

type roundTripFunc func(*http.Request) (*http.Response, error)

func (f roundTripFunc) RoundTrip(req *http.Request) (*http.Response, error) { return f(req) }
