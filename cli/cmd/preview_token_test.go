package cmd

import (
	"bytes"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
)

func runPreviewToken(t *testing.T, serverURL string, args ...string) (string, error) {
	t.Helper()
	old := previewTokenClient
	previewTokenClient = func(previewTokenFlags) (*api.Client, error) {
		return api.New(serverURL, "tok", nil), nil
	}
	t.Cleanup(func() { previewTokenClient = old })

	var out bytes.Buffer
	cmd := newPreviewTokenCmd()
	cmd.SetOut(&out)
	cmd.SetErr(io.Discard)
	cmd.SilenceUsage = true
	cmd.SilenceErrors = true
	cmd.SetArgs(args)
	err := cmd.Execute()

	return out.String(), err
}

func TestPreviewTokenMintPrintsTheTokenAlone(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusCreated)
		fmt.Fprint(w, `{"token":"abc.def"}`)
	}))
	t.Cleanup(server.Close)

	out, err := runPreviewToken(t, server.URL, "mint", "--project", "p1", "--label", "card-1", "--card", "c1")
	if err != nil {
		t.Fatal(err)
	}
	if out != "abc.def\n" {
		t.Fatalf("out = %q", out)
	}
}

func TestPreviewTokenMintFailsWhenTheServerRefuses(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusConflict)
		fmt.Fprint(w, `{"error":"card_finished"}`)
	}))
	t.Cleanup(server.Close)

	out, err := runPreviewToken(t, server.URL, "mint", "--project", "p1", "--label", "card-1")
	if err == nil || !strings.Contains(err.Error(), "card_finished") {
		t.Fatalf("err = %v", err)
	}
	if out != "" {
		t.Fatalf("a refused mint printed %q", out)
	}
}

func TestPreviewTokenRevokeSucceedsWhenNoTokenMatches(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprint(w, `{"revoked":0}`)
	}))
	t.Cleanup(server.Close)

	if _, err := runPreviewToken(t, server.URL, "revoke", "--project", "p1", "--label", "card-1"); err != nil {
		t.Fatal(err)
	}
}

func TestPreviewTokenRequiresProjectAndLabel(t *testing.T) {
	if _, err := runPreviewToken(t, "http://127.0.0.1:1", "mint"); err == nil {
		t.Fatal("mint with no flags succeeded")
	}
}

func TestPreviewTokenClientRefusesAnotherInstance(t *testing.T) {
	useLoginConfigHome(t)
	if err := config.Save(testLogin("https://loupe.example")); err != nil {
		t.Fatal(err)
	}

	_, err := previewTokenClient(previewTokenFlags{instance: "https://other.example"})
	if err == nil || !strings.Contains(err.Error(), "https://loupe.example") {
		t.Fatalf("err = %v", err)
	}
	if _, err := previewTokenClient(previewTokenFlags{instance: "https://loupe.example/"}); err != nil {
		t.Fatal(err)
	}
}

func TestPreviewTokenClientAsksForLoginWhenSignedOut(t *testing.T) {
	useLoginConfigHome(t)

	_, err := previewTokenClient(previewTokenFlags{})
	if err == nil || !strings.Contains(err.Error(), "loupe login") {
		t.Fatalf("err = %v", err)
	}
}
