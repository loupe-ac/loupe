package api

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
)

func versionsServer(t *testing.T, status int, body string) (*Client, *string) {
	t.Helper()
	var auth string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/versions" {
			t.Errorf("path = %s", r.URL.Path)
		}
		auth = r.Header.Get("Authorization")
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)

	return New(srv.URL, "secret", srv.Client()), &auth
}

func TestCheckServerSendsNoTokenAndAcceptsV1(t *testing.T) {
	client, auth := versionsServer(t, http.StatusOK, `{"versions":[{"version":"v1","status":"beta"}],"cliRange":"^2.0"}`)
	if err := client.CheckServer(context.Background()); err != nil {
		t.Fatalf("err = %v", err)
	}
	if *auth != "" {
		t.Fatalf("Authorization = %q, want none", *auth)
	}
}

func TestCheckServerRefusesWhatItCannotUse(t *testing.T) {
	cases := map[string]struct {
		status int
		body   string
		want   error
	}{
		"plain 404":       {http.StatusNotFound, `<html>`, ErrServerTooOld},
		"problem 404":     {http.StatusNotFound, `{"code":"route_not_found"}`, ErrServerTooOld},
		"v1 not served":   {http.StatusOK, `{"versions":[{"version":"v2","status":"stable"}],"cliRange":"^3.0"}`, ErrVersionUnserved},
		"server error":    {http.StatusInternalServerError, `boom`, nil},
		"unreadable body": {http.StatusOK, `not json`, nil},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			client, _ := versionsServer(t, tc.status, tc.body)
			err := client.CheckServer(context.Background())
			if !errors.Is(err, tc.want) || (tc.want == nil && err != nil) {
				t.Fatalf("err = %v, want %v", err, tc.want)
			}
		})
	}
}
