package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/config"
)

const (
	reviewProjectA = "0192f3c4-5d6e-7f80-9123-456789abcdef"
	reviewProjectB = "0192f3c4-5d6e-7f80-9123-456789abcdf0"
	reviewOutsider = "0192f3c4-5d6e-7f80-9123-456789abcdf1"
	reviewCardA    = "0192f3c4-5d6e-7f80-9123-4567890000a1"
	reviewCardB    = "0192f3c4-5d6e-7f80-9123-4567890000b2"
)

// reviewServer behaves like the project resolver for an owner of two projects.
type reviewServer struct {
	*httptest.Server
	mu       sync.Mutex
	requests []string
	queries  []string
	headers  []string
	bodies   []string
}

func newReviewServer(t *testing.T) *reviewServer {
	t.Helper()
	rs := &reviewServer{}
	rs.Server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		data, _ := io.ReadAll(r.Body)
		rs.mu.Lock()
		rs.requests = append(rs.requests, r.Method+" "+r.URL.Path)
		rs.queries = append(rs.queries, r.URL.RawQuery)
		rs.headers = append(rs.headers, r.Header.Get("X-Loupe-Project"))
		rs.bodies = append(rs.bodies, string(data))
		rs.mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/api/projects" {
			fmt.Fprintf(w, `{"sites":[{"id":%q,"slug":"a","name":"Alpha"},{"id":%q,"slug":"b","name":"Beta"}]}`, reviewProjectA, reviewProjectB)

			return
		}
		project := r.Header.Get("X-Loupe-Project")
		if project != reviewProjectA && project != reviewProjectB {
			w.WriteHeader(http.StatusForbidden)
			fmt.Fprint(w, `{"error":"token_not_bound_to_site"}`)

			return
		}
		switch r.URL.Path {
		case "/api/board/cards":
			if r.URL.Query().Get("parent") == "1" {
				fmt.Fprint(w, `{"cards":[{"cardId":"e1","number":9,"title":"Epic","status":"todo"}]}`)

				return
			}
			fmt.Fprint(w, `{"cards":[{"cardId":"c1","number":7,"title":"T","status":"todo"}]}`)
		case "/api/live-review/review":
			// Each destination holds its own notes, as the server scopes them.
			switch r.URL.Query().Get("scope") {
			case "card:" + reviewCardA:
				fmt.Fprint(w, `{"comments":[{"id":"na"}]}`)
			case "card:" + reviewCardB:
				fmt.Fprint(w, `{"comments":[{"id":"nb"}]}`)
			default:
				fmt.Fprint(w, `{"comments":[]}`)
			}
		case "/api/board/feedback":
			w.WriteHeader(http.StatusCreated)
			fmt.Fprint(w, `{"id":"n1"}`)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(rs.Close)

	return rs
}

func (rs *reviewServer) lastQuery() string {
	rs.mu.Lock()
	defer rs.mu.Unlock()

	return rs.queries[len(rs.queries)-1]
}

func (rs *reviewServer) lastHeader() string {
	rs.mu.Lock()
	defer rs.mu.Unlock()

	return rs.headers[len(rs.headers)-1]
}

func runReview(t *testing.T, stdin string, args ...string) (string, error) {
	t.Helper()
	var out bytes.Buffer
	cmd := newReviewCmd()
	cmd.SilenceUsage = true
	cmd.SetOut(&out)
	cmd.SetErr(io.Discard)
	cmd.SetIn(strings.NewReader(stdin))
	cmd.SetArgs(args)
	err := cmd.Execute()

	return strings.TrimSpace(out.String()), err
}

func signedInTo(t *testing.T) *reviewServer {
	t.Helper()
	useLoginConfigHome(t)
	rs := newReviewServer(t)
	if err := config.Save(testLogin(rs.URL)); err != nil {
		t.Fatal(err)
	}

	return rs
}

func TestReviewTargetsNotesAndSaveSendTheGivenProject(t *testing.T) {
	rs := signedInTo(t)

	got, err := runReview(t, "", "targets", "--project", reviewProjectB, "--query", "x")
	if err != nil || !strings.HasPrefix(got, `{"cards":[{`) || rs.lastHeader() != reviewProjectB {
		t.Fatalf("targets = %q, %v, header %q", got, err, rs.lastHeader())
	}
	got, err = runReview(t, "", "notes", "--project", reviewProjectA, "--scope", "card:"+reviewCardA)
	if err != nil || got != `{"notes":{"comments":[{"id":"na"}]}}` || rs.lastHeader() != reviewProjectA {
		t.Fatalf("notes = %q, %v, header %q", got, err, rs.lastHeader())
	}
	got, err = runReview(t, `{"comment":"hi"}`, "save", "--project", reviewProjectA)
	if err != nil || got != `{"id":"n1"}` || rs.lastHeader() != reviewProjectA {
		t.Fatalf("save = %q, %v, header %q", got, err, rs.lastHeader())
	}
	if rs.bodies[len(rs.bodies)-1] != `{"comment":"hi"}` {
		t.Fatalf("save posted %q", rs.bodies[len(rs.bodies)-1])
	}
}

func TestReviewNotesListOnlyTheGivenDestination(t *testing.T) {
	rs := signedInTo(t)

	for scope, want := range map[string]string{
		"card:" + reviewCardA:                      `{"notes":{"comments":[{"id":"na"}]}}`,
		"card:" + reviewCardB:                      `{"notes":{"comments":[{"id":"nb"}]}}`,
		"epic:" + reviewCardA:                      `{"notes":{"comments":[]}}`,
		"cards:" + reviewCardA + "," + reviewCardB: `{"notes":{"comments":[]}}`,
	} {
		got, err := runReview(t, "", "notes", "--project", reviewProjectA, "--scope", scope)
		if err != nil || got != want {
			t.Fatalf("%s = %q, %v, want %q", scope, got, err, want)
		}
		if q, _ := url.ParseQuery(rs.lastQuery()); q.Get("scope") != scope || q.Get("surface") != "ios-simulator" {
			t.Fatalf("%s sent query %q", scope, rs.lastQuery())
		}
	}
}

func TestReviewTargetsAsksForParentCards(t *testing.T) {
	rs := signedInTo(t)

	got, err := runReview(t, "", "targets", "--project", reviewProjectA, "--parent")
	if err != nil || got != `{"cards":[{"cardId":"e1","number":9,"title":"Epic","status":"todo"}]}` || rs.lastQuery() != "parent=1" {
		t.Fatalf("parent targets = %q, %v, query %q", got, err, rs.lastQuery())
	}
}

func TestReviewProjectsSendsNoHeader(t *testing.T) {
	rs := signedInTo(t)

	got, err := runReview(t, "", "projects")
	want := fmt.Sprintf(`{"projects":[{"id":%q,"name":"Alpha"},{"id":%q,"name":"Beta"}]}`, reviewProjectA, reviewProjectB)
	if err != nil || got != want || rs.lastHeader() != "" {
		t.Fatalf("projects = %q, %v, header %q", got, err, rs.lastHeader())
	}
}

func TestReviewStatus(t *testing.T) {
	rs := signedInTo(t)

	cases := []struct {
		args []string
		want string
	}{
		{[]string{"status"}, `{"state":"ok"}`},
		{[]string{"status", "--project", reviewProjectB}, `{"state":"ok"}`},
		{[]string{"status", "--project", reviewOutsider}, `{"state":"notOwner"}`},
	}
	for _, tc := range cases {
		if got, err := runReview(t, "", tc.args...); err != nil || got != tc.want {
			t.Fatalf("%v = %q, %v, want %q", tc.args, got, err, tc.want)
		}
	}
	if rs.lastHeader() != reviewOutsider || rs.lastQuery() != "scope=&surface=ios-simulator" {
		t.Fatalf("last probe header %q, query %q", rs.lastHeader(), rs.lastQuery())
	}
}

func TestReviewStatusWithNoProjectAndSignedOut(t *testing.T) {
	useLoginConfigHome(t)
	if got, err := runReview(t, "", "status"); err != nil || got != `{"state":"signedOut"}` {
		t.Fatalf("signed out = %q, %v", got, err)
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/projects" {
			fmt.Fprint(w, `{"sites":[]}`)

			return
		}
		w.WriteHeader(http.StatusForbidden)
		fmt.Fprint(w, `{"error":"insufficient_scope"}`)
	}))
	t.Cleanup(server.Close)
	if err := config.Save(testLogin(server.URL)); err != nil {
		t.Fatal(err)
	}
	if got, err := runReview(t, "", "status"); err != nil || got != `{"state":"noProject"}` {
		t.Fatalf("no project = %q, %v", got, err)
	}
	if got, err := runReview(t, "", "status", "--project", reviewProjectA); err != nil || got != `{"state":"signedOut"}` {
		t.Fatalf("scope missing = %q, %v", got, err)
	}
}

func TestReviewRefusesABadProjectBeforeAnyRequest(t *testing.T) {
	rs := signedInTo(t)

	for _, args := range [][]string{
		{"targets", "--project", "nope"},
		{"notes", "--project", strings.ToUpper(reviewProjectA), "--scope", "card:" + reviewCardA},
		{"notes", "--scope", "card:" + reviewCardA},
		{"save", "--project", "nope"},
		{"status", "--project", "nope"},
	} {
		got, err := runReview(t, `{}`, args...)
		if err == nil || !strings.Contains(got, `"error":"invalid_project"`) {
			t.Fatalf("%v = %q, %v", args, got, err)
		}
	}
	if len(rs.requests) != 0 {
		t.Fatalf("requests sent: %v", rs.requests)
	}
}

func TestReviewNotesRefusesABadScopeBeforeAnyRequest(t *testing.T) {
	rs := signedInTo(t)

	for _, scope := range []string{"", "card:", "card:nope", "card:" + strings.ToUpper(reviewCardA), "board:" + reviewCardA, "cards:", "cards:" + reviewCardA + ","} {
		args := []string{"notes", "--project", reviewProjectA}
		if scope != "" {
			args = append(args, "--scope", scope)
		}
		got, err := runReview(t, "", args...)
		if err == nil || !strings.Contains(got, `"error":"invalid_scope"`) {
			t.Fatalf("%q = %q, %v", scope, got, err)
		}
	}
	if len(rs.requests) != 0 {
		t.Fatalf("requests sent: %v", rs.requests)
	}
}

func TestReviewMapsServerRefusals(t *testing.T) {
	signedInTo(t)

	got, err := runReview(t, "", "notes", "--project", reviewOutsider, "--scope", "card:"+reviewCardA)
	if err == nil || !strings.Contains(got, `"error":"not_owner"`) {
		t.Fatalf("notes = %q, %v", got, err)
	}
	if strings.Contains(got, "access-1") || strings.Contains(got, "refresh-1") {
		t.Fatalf("output holds a token: %q", got)
	}
}

func TestReviewSaveRefusesBadInput(t *testing.T) {
	rs := signedInTo(t)

	for name, input := range map[string]string{
		"not json":  "hello",
		"array":     "[1]",
		"too large": `{"a":"` + strings.Repeat("x", maxReviewInput) + `"}`,
	} {
		got, err := runReview(t, input, "save", "--project", reviewProjectA)
		if err == nil || !json.Valid([]byte(got)) || !strings.Contains(got, `"error":"`) {
			t.Fatalf("%s: %q, %v", name, got, err)
		}
	}
	if len(rs.requests) != 0 {
		t.Fatalf("requests sent: %v", rs.requests)
	}
}

// signInServer answers the device flow. Each token request takes the next
// answer of tokenAnswers, and the last answer repeats.
type signInServer struct {
	*httptest.Server
	mu           sync.Mutex
	tokenAnswers []string
	tokenCalls   int
}

func newSignInServer(t *testing.T, tokenAnswers ...string) *signInServer {
	t.Helper()
	ss := &signInServer{tokenAnswers: tokenAnswers}
	ss.Server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/oauth/device-authorization":
			fmt.Fprint(w, `{"device_code":"dev-1","user_code":"BCDFGHJK","verification_uri":"https://loupe.test/d","expires_in":600,"interval":1}`)
		case "/oauth/token":
			ss.mu.Lock()
			answer := ss.tokenAnswers[min(ss.tokenCalls, len(ss.tokenAnswers)-1)]
			ss.tokenCalls++
			ss.mu.Unlock()
			if strings.Contains(answer, `"error"`) {
				w.WriteHeader(http.StatusBadRequest)
			}
			fmt.Fprint(w, answer)
		case "/api/projects":
			fmt.Fprintf(w, `{"sites":[{"id":%q,"slug":"a","name":"Alpha"}]}`, reviewProjectA)
		case "/api/live-review/review":
			fmt.Fprint(w, `{"comments":[]}`)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(ss.Close)
	t.Setenv("LOUPE_URL", ss.URL)

	return ss
}

func (ss *signInServer) calls() int {
	ss.mu.Lock()
	defer ss.mu.Unlock()

	return ss.tokenCalls
}

// useReviewClock lets a test move the clock of the pending sign-in.
func useReviewClock(t *testing.T) func(time.Duration) {
	t.Helper()
	now := time.Now()
	reviewNow = func() time.Time { return now }
	t.Cleanup(func() { reviewNow = time.Now })

	return func(d time.Duration) { now = now.Add(d) }
}

const signInTokens = `{"token_type":"Bearer","access_token":"eyJ.access","refresh_token":"refresh-1","expires_in":3600}`

func TestReviewSignInPrintsTheCodeAndExits(t *testing.T) {
	useLoginConfigHome(t)
	ss := newSignInServer(t, signInTokens)

	got, err := runReview(t, "", "sign-in")
	if err != nil || got != `{"code":"BCDF-GHJK","url":"https://loupe.test/d"}` {
		t.Fatalf("sign-in = %q, %v", got, err)
	}
	if ss.calls() != 0 {
		t.Fatalf("sign-in asked for the tokens %d times", ss.calls())
	}
	if _, err := config.Load(); err == nil {
		t.Fatal("sign-in saved a login before approval")
	}
	if pending, err := config.LoadPendingSignIn(); err != nil || pending == nil || pending.DeviceCode != "dev-1" {
		t.Fatalf("pending sign-in = %+v, %v", pending, err)
	}
}

func TestReviewStatusFinishesThePendingSignIn(t *testing.T) {
	useLoginConfigHome(t)
	advance := useReviewClock(t)
	ss := newSignInServer(t, `{"error":"authorization_pending"}`, `{"error":"slow_down"}`, signInTokens)
	if _, err := runReview(t, "", "sign-in"); err != nil {
		t.Fatal(err)
	}

	steps := []struct {
		wait  time.Duration
		want  string
		calls int
	}{
		{0, `{"state":"pending"}`, 0},
		{time.Second, `{"state":"pending"}`, 1},
		{time.Second, `{"state":"pending"}`, 2},
		{time.Second, `{"state":"pending"}`, 2},
		{5 * time.Second, `{"state":"ok"}`, 3},
		{time.Minute, `{"state":"ok"}`, 3},
	}
	for i, step := range steps {
		advance(step.wait)
		got, err := runReview(t, "", "status")
		if err != nil || got != step.want || ss.calls() != step.calls {
			t.Fatalf("step %d: status = %q, %v after %d token calls", i, got, err, ss.calls())
		}
		if strings.Contains(got, "eyJ.access") || strings.Contains(got, "dev-1") {
			t.Fatalf("output holds a secret: %q", got)
		}
	}
	if _, err := config.Load(); err != nil {
		t.Fatalf("tokens not saved: %v", err)
	}
	if pending, err := config.LoadPendingSignIn(); err != nil || pending != nil {
		t.Fatalf("pending sign-in kept: %+v, %v", pending, err)
	}
}

func TestALoginEndsThePendingSignIn(t *testing.T) {
	useLoginConfigHome(t)
	ss := newSignInServer(t, `{"error":"authorization_pending"}`)
	if _, err := runReview(t, "", "sign-in"); err != nil {
		t.Fatal(err)
	}

	tokens := config.OAuthTokens{AccessToken: "access-1", RefreshToken: "refresh-1", ExpiresAt: time.Now().Add(time.Hour)}
	if err := saveDeviceLogin(context.Background(), ss.URL, deviceFlowClient(), tokens); err != nil {
		t.Fatal(err)
	}
	if pending, err := config.LoadPendingSignIn(); err != nil || pending != nil {
		t.Fatalf("pending sign-in kept: %+v, %v", pending, err)
	}
	if got, err := runReview(t, "", "status"); err != nil || got != `{"state":"ok"}` || ss.calls() != 0 {
		t.Fatalf("status = %q, %v after %d token calls", got, err, ss.calls())
	}
}

func TestReviewStatusDropsADeniedOrExpiredSignIn(t *testing.T) {
	for name, tc := range map[string]struct {
		wait  time.Duration
		calls int
	}{
		"denied":  {2 * time.Second, 1},
		"expired": {11 * time.Minute, 0},
	} {
		t.Run(name, func(t *testing.T) {
			useLoginConfigHome(t)
			advance := useReviewClock(t)
			ss := newSignInServer(t, `{"error":"access_denied"}`)
			if _, err := runReview(t, "", "sign-in"); err != nil {
				t.Fatal(err)
			}

			advance(tc.wait)
			if got, err := runReview(t, "", "status"); err != nil || got != `{"state":"signedOut"}` || ss.calls() != tc.calls {
				t.Fatalf("status = %q, %v after %d token calls", got, err, ss.calls())
			}
			if pending, err := config.LoadPendingSignIn(); err != nil || pending != nil {
				t.Fatalf("pending sign-in kept: %+v, %v", pending, err)
			}
		})
	}
}

func TestReviewReportsAnExpiredLoginAsSignedOut(t *testing.T) {
	useLoginConfigHome(t)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/oauth/token" {
			w.WriteHeader(http.StatusBadRequest)
			fmt.Fprint(w, `{"error":"invalid_grant"}`)

			return
		}
		w.WriteHeader(http.StatusUnauthorized)
	}))
	t.Cleanup(server.Close)
	save := func() {
		login := testLogin(server.URL)
		login.OAuth.ExpiresAt = time.Now().Add(-time.Hour)
		if err := config.Save(login); err != nil {
			t.Fatal(err)
		}
	}

	for _, args := range [][]string{{"status"}, {"status", "--project", reviewProjectA}} {
		save()
		if got, err := runReview(t, "", args...); err != nil || got != `{"state":"signedOut"}` {
			t.Fatalf("%v = %q, %v", args, got, err)
		}
	}
	for _, args := range [][]string{{"projects"}, {"notes", "--project", reviewProjectA, "--scope", "card:" + reviewCardA}} {
		save()
		if got, err := runReview(t, "", args...); err == nil || !strings.Contains(got, `"error":"signed_out"`) {
			t.Fatalf("%v = %q, %v", args, got, err)
		}
	}
}
