package api

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

func putOutput(t *testing.T, status int, answer string) (string, string, string, RunOutputAnswer, error) {
	t.Helper()

	var gotPath, gotMethod, gotAuth, gotBody string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotMethod, gotAuth = r.URL.EscapedPath(), r.Method, r.Header.Get("Authorization")
		b, _ := io.ReadAll(r.Body)
		gotBody = string(b)
		w.WriteHeader(status)
		_, _ = w.Write([]byte(answer))
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "t", server.Client()).PutRunOutput(context.Background(), "my/project", testRunID, "bridge-1", 5, "héllo\n")
	if gotMethod != http.MethodPut {
		t.Fatalf("method = %s, want PUT", gotMethod)
	}

	return gotPath, gotAuth, gotBody, got, err
}

func TestPutRunOutputSendsTheChunk(t *testing.T) {
	path, auth, body, got, err := putOutput(t, http.StatusOK, `{"length":11,"capped":false}`)
	if err != nil {
		t.Fatal(err)
	}
	if path != "/api/projects/my%2Fproject/worker-runs/"+testRunID+"/output" {
		t.Fatalf("path = %s", path)
	}
	if auth != "Bearer t" {
		t.Fatalf("authorization = %q", auth)
	}
	if want := `{"bridgeId":"bridge-1","offset":5,"text":"héllo\n"}`; body != want {
		t.Fatalf("body = %s, want %s", body, want)
	}
	if got != (RunOutputAnswer{Length: 11}) {
		t.Fatalf("answer = %+v", got)
	}
}

func TestPutRunOutputReadsTheCap(t *testing.T) {
	_, _, _, got, err := putOutput(t, http.StatusOK, `{"length":1048576,"capped":true}`)
	if err != nil || !got.Capped || got.Length != 1048576 {
		t.Fatalf("answer = %+v, err = %v", got, err)
	}
}

func TestPutRunOutputReadsEachFailure(t *testing.T) {
	for status, refused := range map[int]bool{
		http.StatusNotFound:            true,
		http.StatusUnprocessableEntity: true,
		http.StatusTooManyRequests:     false,
		http.StatusBadGateway:          false,
	} {
		_, _, _, _, err := putOutput(t, status, `{"error":"run_not_found"}`)
		if err == nil {
			t.Fatalf("status %d: no error", status)
		}
		if errors.Is(err, ErrReportRefused) != refused {
			t.Fatalf("status %d: refused = %v, err = %v", status, !refused, err)
		}
	}
}

func TestPutRunOutputRejectsAnUnreadableAnswer(t *testing.T) {
	if _, _, _, _, err := putOutput(t, http.StatusOK, `nope`); err == nil {
		t.Fatal("no error for an unreadable answer")
	}
}
