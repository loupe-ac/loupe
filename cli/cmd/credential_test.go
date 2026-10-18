package cmd

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
)

func TestParseGitHubRemote(t *testing.T) {
	cases := map[string]string{
		"https://github.com/acme/site":              "acme/site",
		"https://github.com/acme/site.git":          "acme/site",
		"https://github.com/acme/site/":             "acme/site",
		"https://user@github.com/acme/site.git":     "acme/site",
		"https://x-access-token:t@github.com/a/b":   "",
		"https://user:@github.com/a/b":              "",
		"https://GitHub.com/Acme/Site.git":          "Acme/Site",
		"git@github.com:acme/site.git":              "acme/site",
		"git@github.com:acme/site":                  "acme/site",
		"ssh://git@github.com/acme/site.git":        "acme/site",
		"ssh://git@github.com/acme/my.repo.name":    "acme/my.repo.name",
		"https://gitlab.com/acme/site.git":          "",
		"git@gitlab.com:acme/site.git":              "",
		"https://github.com/acme":                   "",
		"https://github.com/acme/site/extra":        "",
		"https://github.com//site":                  "",
		"http://github.com/acme/site":               "",
		"/srv/git/site.git":                         "",
		"":                                          "",
		"git@github.com:acme":                       "",
		"https://github.com.evil.example/acme/site": "",
		"ssh://git@github.com:22/acme/site.git":     "",
		"ssh://git@GitHub.com/acme/site.git":        "",
		"ssh://github.com/acme/site.git":            "",
		"ssh://git@ssh.github.com:443/acme/site":    "",
		"git+ssh://git@github.com/acme/site.git":    "",
		"git@GitHub.com:acme/site.git":              "",
		"github.com:acme/site.git":                  "",
		"deploy@github.com:acme/site.git":           "",
	}
	for remote, want := range cases {
		got, err := parseGitHubRemote(remote)
		if want == "" {
			if err == nil {
				t.Errorf("parseGitHubRemote(%q) = %q, want an error", remote, got)
			}

			continue
		}
		if err != nil || got != want {
			t.Errorf("parseGitHubRemote(%q) = %q, %v; want %q", remote, got, err, want)
		}
	}
}

func TestEveryGitHubSSHAddressIsRefusedOrSentThroughHTTPS(t *testing.T) {
	dir := gitFolder(t)
	remotes := []string{
		"git@github.com:acme/site.git", "ssh://git@github.com/acme/site.git", "ssh://git@github.com:22/acme/site.git",
		"ssh://git@GitHub.com/acme/site.git", "ssh://github.com/acme/site", "ssh://git@ssh.github.com:443/acme/site",
		"git+ssh://git@github.com/acme/site", "ssh+git://git@github.com/acme/site", "git@GitHub.com:acme/site",
		"github.com:acme/site", "deploy@github.com:acme/site", "ssh.github.com:acme/site", "[git@github.com:22]:acme/site",
		"[github.com:22]:acme/site",
	}
	env := workerEnv(os.Environ(), "s1", nil, credEnvFixture())
	for _, remote := range remotes {
		_, err := parseGitHubRemote(remote)
		if errors.Is(err, errNotGitHub) {
			t.Errorf("parseGitHubRemote(%q) leaves the worker on the machine setup", remote)

			continue
		}
		if err != nil {
			continue
		}
		cmd := exec.Command("git", "-C", dir, "ls-remote", "--get-url", remote)
		cmd.Env = env
		out, err := cmd.Output()
		if err != nil || !strings.HasPrefix(string(out), "https://github.com/") {
			t.Errorf("git sends the accepted address %q to %q, %v; want https://github.com/", remote, out, err)
		}
	}
}

// gitFolder makes a git folder and runs each git command in it.
func gitFolder(t *testing.T, commands ...[]string) string {
	t.Helper()
	if _, err := exec.LookPath("git"); err != nil {
		t.Skip("git is not installed")
	}
	dir := t.TempDir()
	for _, args := range append([][]string{{"init", "-q"}}, commands...) {
		if out, err := exec.Command("git", append([]string{"-C", dir}, args...)...).CombinedOutput(); err != nil {
			t.Fatalf("git %v: %v: %s", args, err, out)
		}
	}

	return dir
}

func TestGitRepositoryReadsTheOriginRemote(t *testing.T) {
	dir := gitFolder(t, []string{"remote", "add", "origin", "git@github.com:acme/site.git"})
	got, err := gitRepository(context.Background(), dir)
	if err != nil || got != "acme/site" {
		t.Fatalf("gitRepository = %q, %v", got, err)
	}
}

// Only a missing origin and an origin off GitHub leave the worker on the git
// setup of the machine. Every other failure must keep it managed.
func TestGitRepositoryFailsClosedUnlessTheRemoteIsAbsentOrOffGitHub(t *testing.T) {
	cases := []struct {
		name     string
		dir      string
		wantRepo string
		want     error
	}{
		{"no origin", gitFolder(t), "", errNoOrigin},
		{"not on GitHub", gitFolder(t, []string{"remote", "add", "origin", "git@gitlab.com:acme/site.git"}), "", errNotGitHub},
		{"not a git folder", t.TempDir(), "", nil},
		{"push goes off GitHub", gitFolder(t,
			[]string{"remote", "add", "origin", "git@github.com:acme/site.git"},
			[]string{"config", "remote.origin.pushurl", "git@gitlab.com:acme/site.git"}), "", nil},
		{"push comes from GitHub only", gitFolder(t,
			[]string{"remote", "add", "origin", "git@gitlab.com:acme/site.git"},
			[]string{"config", "remote.origin.pushurl", "git@github.com:acme/site.git"}), "", nil},
		{"push to another repository", gitFolder(t,
			[]string{"remote", "add", "origin", "git@github.com:acme/site.git"},
			[]string{"config", "remote.origin.pushurl", "git@github.com:acme/other.git"}), "", nil},
		{"a second push address goes off GitHub", gitFolder(t,
			[]string{"remote", "add", "origin", "git@github.com:acme/site.git"},
			[]string{"config", "--add", "remote.origin.pushurl", "git@github.com:acme/site.git"},
			[]string{"config", "--add", "remote.origin.pushurl", "git@gitlab.com:acme/site.git"}), "", nil},
		{"a second url goes to another repository", gitFolder(t,
			[]string{"remote", "add", "origin", "git@github.com:acme/site.git"},
			[]string{"config", "--add", "remote.origin.url", "git@github.com:acme/other.git"}), "", nil},
		{"a push address holds a password", gitFolder(t,
			[]string{"remote", "add", "origin", "https://github.com/acme/site"},
			[]string{"config", "remote.origin.pushurl", "https://x-access-token:secret@github.com/acme/site"}), "", nil},
		{"every push address is off GitHub", gitFolder(t,
			[]string{"remote", "add", "origin", "git@gitlab.com:acme/site.git"},
			[]string{"config", "--add", "remote.origin.pushurl", "git@gitlab.com:acme/site.git"},
			[]string{"config", "--add", "remote.origin.pushurl", "https://gitlab.com/acme/site.git"}), "", errNotGitHub},
		{"every push address names the repository", gitFolder(t,
			[]string{"remote", "add", "origin", "git@github.com:acme/site.git"},
			[]string{"config", "--add", "remote.origin.pushurl", "git@github.com:acme/site.git"},
			[]string{"config", "--add", "remote.origin.pushurl", "https://github.com/acme/site"}), "acme/site", nil},
		{"push to the same repository", gitFolder(t,
			[]string{"remote", "add", "origin", "https://github.com/acme/site"},
			[]string{"config", "remote.origin.pushurl", "git@github.com:ACME/site.git"}), "ACME/site", nil},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got, err := gitRepository(context.Background(), c.dir)
			if c.wantRepo != "" {
				if err != nil || got != c.wantRepo {
					t.Fatalf("gitRepository = %q, %v; want %q", got, err, c.wantRepo)
				}

				return
			}
			if err == nil {
				t.Fatalf("gitRepository = %q, want an error", got)
			}
			unmanaged := errors.Is(err, errNoOrigin) || errors.Is(err, errNotGitHub)
			if c.want != nil && !errors.Is(err, c.want) || c.want == nil && unmanaged {
				t.Fatalf("err = %v, want %v", err, c.want)
			}
		})
	}
}

func TestParseGitHubRemoteKeepsThePasswordOutOfItsError(t *testing.T) {
	_, err := parseGitHubRemote("https://x-access-token:secret@github.com/acme/site")
	if err == nil || strings.Contains(err.Error(), "secret") {
		t.Fatalf("err = %v, want an error without the password", err)
	}
}

// credClock moves only when a test moves it.
type credClock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *credClock) now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.t
}

func (c *credClock) advance(d time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.t = c.t.Add(d)
}

type tokenFixture struct {
	broker *credentialBroker
	clock  *credClock
	calls  atomic.Int32
	// next is the error the following ask returns.
	mu   sync.Mutex
	fail error
}

func newTokenFixture() *tokenFixture {
	f := &tokenFixture{clock: &credClock{t: time.Date(2026, 10, 18, 12, 0, 0, 0, time.UTC)}}
	f.broker = &credentialBroker{
		now:        f.clock.now,
		socket:     "/sock",
		repository: func(context.Context, string) (string, error) { return "acme/site", nil },
		issue: func(_ context.Context, handle, repository string) (string, time.Time, error) {
			n := f.calls.Add(1)
			f.mu.Lock()
			err := f.fail
			f.mu.Unlock()
			if err != nil {
				return "", time.Time{}, err
			}

			return fmt.Sprintf("tok-%s-%s-%d", handle, repository, n), f.clock.now().Add(time.Hour), nil
		},
	}

	return f
}

func (f *tokenFixture) failWith(err error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.fail = err
}

func TestTokenIsCachedUntilFiveMinutesRemain(t *testing.T) {
	f := newTokenFixture()
	ctx := context.Background()

	first, _, err := f.broker.token(ctx, "p1", "acme/site")
	if err != nil {
		t.Fatal(err)
	}
	f.clock.advance(54 * time.Minute)
	again, _, _ := f.broker.token(ctx, "p1", "ACME/Site")
	if again != first || f.calls.Load() != 1 {
		t.Fatalf("token = %q after %d calls, want the cached %q", again, f.calls.Load(), first)
	}

	f.clock.advance(time.Minute + time.Second)
	renewed, _, _ := f.broker.token(ctx, "p1", "acme/site")
	if renewed == first || f.calls.Load() != 2 {
		t.Fatalf("token = %q after %d calls, want a new token", renewed, f.calls.Load())
	}
}

func TestTokensAreCachedPerProjectAndRepository(t *testing.T) {
	f := newTokenFixture()
	for _, k := range [][2]string{{"p1", "acme/site"}, {"p2", "acme/site"}, {"p1", "acme/other"}, {"p1", "acme/site"}} {
		if _, _, err := f.broker.token(context.Background(), k[0], k[1]); err != nil {
			t.Fatal(err)
		}
	}
	if f.calls.Load() != 3 {
		t.Fatalf("calls = %d, want 3", f.calls.Load())
	}
}

func TestOneAskServesAllTheRequestsOfAKey(t *testing.T) {
	f := newTokenFixture()
	release := make(chan struct{})
	inner := f.broker.issue
	f.broker.issue = func(ctx context.Context, h, r string) (string, time.Time, error) {
		<-release

		return inner(ctx, h, r)
	}
	var wg sync.WaitGroup
	got := make([]string, 20)
	for i := range got {
		wg.Add(1)
		go func() {
			defer wg.Done()
			got[i], _, _ = f.broker.token(context.Background(), "p1", "acme/site")
		}()
	}
	time.Sleep(50 * time.Millisecond)
	close(release)
	wg.Wait()

	if f.calls.Load() != 1 {
		t.Fatalf("calls = %d, want 1", f.calls.Load())
	}
	for _, token := range got {
		if token != got[0] || token == "" {
			t.Fatalf("tokens = %q", got)
		}
	}
}

func TestALiveTokenSurvivesAFailedRefresh(t *testing.T) {
	f := newTokenFixture()
	first, _, _ := f.broker.token(context.Background(), "p1", "acme/site")
	f.clock.advance(58 * time.Minute)
	f.failWith(errors.New("github_unavailable"))

	got, _, err := f.broker.token(context.Background(), "p1", "acme/site")
	if err != nil || got != first {
		t.Fatalf("token = %q, %v; want the live %q", got, err, first)
	}
	f.clock.advance(3 * time.Minute)
	if _, _, err := f.broker.token(context.Background(), "p1", "acme/site"); err == nil {
		t.Fatal("an ended token must not be served")
	}
}

func TestAFailedAskIsNotCached(t *testing.T) {
	f := newTokenFixture()
	f.failWith(errors.New("no_installation"))
	if _, _, err := f.broker.token(context.Background(), "p1", "acme/site"); err == nil {
		t.Fatal("want an error")
	}
	f.failWith(nil)
	if tok, _, err := f.broker.token(context.Background(), "p1", "acme/site"); err != nil || tok == "" {
		t.Fatalf("token = %q, %v", tok, err)
	}
}

// The first token decides whether the worker gets managed credentials.
func TestManagedDecisionMatrix(t *testing.T) {
	cases := []struct {
		name        string
		err         error
		remoteErr   error
		wantManaged bool
	}{
		{"token", nil, nil, true},
		{"not managed", api.ErrGitIdentityNotManaged, nil, false},
		{"unsupported", api.ErrGitTokenUnsupported, nil, false},
		{"wrapped not managed", fmt.Errorf("x: %w", api.ErrGitIdentityNotManaged), nil, false},
		{"no installation", errors.New("the server refused to issue a git token (HTTP 409): no_installation"), nil, true},
		{"github down", errors.New("HTTP 502: github_unavailable"), nil, true},
		{"network", errors.New("request a git token: connection refused"), nil, true},
		{"remote is not GitHub", nil, fmt.Errorf("%w: %q", errNotGitHub, "git@gitlab.com:a/b.git"), false},
		{"no origin", nil, fmt.Errorf("%w in /work", errNoOrigin), false},
		{"unreadable remote", nil, errors.New("git failed"), true},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			f := newTokenFixture()
			f.failWith(c.err)
			if c.remoteErr != nil {
				f.broker.repository = func(context.Context, string) (string, error) { return "", c.remoteErr }
			}
			managed, err := f.broker.manages(context.Background(), "p1", "/work")
			if managed != c.wantManaged {
				t.Fatalf("managed = %v, want %v (err %v)", managed, c.wantManaged, err)
			}
			if !c.wantManaged && err == nil {
				t.Fatal("an unmanaged answer carries its cause")
			}
		})
	}
}

func TestManagedDecisionWarmsTheCache(t *testing.T) {
	f := newTokenFixture()
	if managed, _ := f.broker.manages(context.Background(), "p1", "/work"); !managed {
		t.Fatal("want managed")
	}
	res := f.broker.answerFor("r1", credentialRun{handle: "p1", dir: "/work"})
	if !res.OK || f.calls.Load() != 1 {
		t.Fatalf("answer = %+v after %d calls", res, f.calls.Load())
	}
}

// A new worker asks the server even when the cache holds a token, so a project
// setting turned off since then leaves the worker on the machine setup.
func TestManagedDecisionSkipsTheCache(t *testing.T) {
	f := newTokenFixture()
	if _, _, err := f.broker.token(context.Background(), "p1", "acme/site"); err != nil {
		t.Fatal(err)
	}
	f.failWith(api.ErrGitIdentityNotManaged)
	if managed, _ := f.broker.manages(context.Background(), "p1", "/work"); managed || f.calls.Load() != 2 {
		t.Fatalf("managed = %v after %d calls, want unmanaged after 2", managed, f.calls.Load())
	}
	if tok, _, err := f.broker.token(context.Background(), "p1", "acme/site"); err == nil {
		t.Fatalf("token = %q, want no cached token once the server stops managing the project", tok)
	}
}

func TestANotManagedAnswerEndsTheCachedToken(t *testing.T) {
	f := newTokenFixture()
	f.broker.token(context.Background(), "p1", "acme/site")
	f.clock.advance(58 * time.Minute)
	f.failWith(fmt.Errorf("x: %w", api.ErrGitIdentityNotManaged))
	if tok, _, err := f.broker.token(context.Background(), "p1", "acme/site"); err == nil {
		t.Fatalf("token = %q, want the not-managed error", tok)
	}
}

// answerFor registers a run and answers for it.
func (b *credentialBroker) answerFor(runID string, run credentialRun) credentialResult {
	b.register(runID, run)

	return b.answer(context.Background(), runID)
}

func TestAnswerGivesTheAppTokenToAManagedRun(t *testing.T) {
	f := newTokenFixture()
	res := f.broker.answerFor("r1", credentialRun{handle: "p1", dir: "/work"})
	if !res.OK || res.Login != "x-access-token" || res.Token != "tok-p1-acme/site-1" || !res.ExpiresAt.Equal(f.clock.now().Add(time.Hour)) {
		t.Fatalf("answer = %+v", res)
	}
}

func TestAnswerGivesTheStoredTokenToAnAgentRunWithoutAskingTheServer(t *testing.T) {
	f := newTokenFixture()
	f.broker.agent = &config.AgentAccount{Token: "ghp_agent", Login: "loupe-bot", ID: 7}
	res := f.broker.answerFor("r1", credentialRun{handle: "p1", dir: "/work", agent: true})
	if !res.OK || res.Login != "loupe-bot" || res.Token != "ghp_agent" || !res.ExpiresAt.IsZero() || f.calls.Load() != 0 {
		t.Fatalf("answer = %+v after %d calls", res, f.calls.Load())
	}
}

func TestAnswerRefusesAnUnknownOrForgottenRun(t *testing.T) {
	f := newTokenFixture()
	if res := f.broker.answer(context.Background(), "nope"); res.OK || res.Token != "" || res.Problem == "" {
		t.Fatalf("answer = %+v", res)
	}
	f.broker.register("r1", credentialRun{handle: "p1", dir: "/work"})
	f.broker.forget("r1")
	if res := f.broker.answer(context.Background(), "r1"); res.OK {
		t.Fatalf("answer = %+v", res)
	}
}

func TestAnswerNamesTheProblemOfAFailedAsk(t *testing.T) {
	f := newTokenFixture()
	f.failWith(errors.New("the server refused to issue a git token (HTTP 409): missing_grant (contents)"))
	res := f.broker.answerFor("r1", credentialRun{handle: "p1", dir: "/work"})
	if res.OK || res.Token != "" || !strings.Contains(res.Problem, "missing_grant (contents)") || !strings.Contains(res.Problem, "acme/site") {
		t.Fatalf("answer = %+v", res)
	}
}

// serveCredentials listens on a fresh socket and answers with ops.
func serveCredentials(t *testing.T, credential func(context.Context, string) credentialResult) string {
	t.Helper()
	path := testSocket(t)
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := serveControl(ctx, ln, controlOps{credential: credential})
	t.Cleanup(func() {
		cancel()
		<-done
	})

	return path
}

func TestTheControlSocketAnswersACredentialRequest(t *testing.T) {
	var asked string
	path := serveCredentials(t, func(_ context.Context, runID string) credentialResult {
		asked = runID

		return credentialResult{OK: true, Login: "x-access-token", Token: "tok-1"}
	})

	res, err := requestCredential(path, "run-7", time.Second, 10*time.Millisecond)
	if err != nil || !res.OK || res.Login != "x-access-token" || res.Token != "tok-1" || asked != "run-7" {
		t.Fatalf("answer = %+v, %v, asked %q", res, err, asked)
	}
	info, err := os.Stat(path)
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("socket = %v, %v; want mode 0600", info, err)
	}
}

func TestTheControlSocketRefusesAMalformedCredentialRequest(t *testing.T) {
	path := serveCredentials(t, func(context.Context, string) credentialResult { return credentialResult{OK: true, Token: "t"} })

	for _, line := range []string{"not json\n", `{"op":"credential","runId":` + strings.Repeat("x", 2000) + "}\n", `{"op":"nope"}` + "\n"} {
		res := askControl(t, path, line)
		if res.OK || len(res.Problems) != 1 {
			t.Fatalf("answer to %.40q = %+v", line, res)
		}
	}
}

func TestTheControlSocketNamesTheCredentialOpWhenItHasNone(t *testing.T) {
	path := testSocket(t)
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := serveControl(ctx, ln, controlOps{})
	t.Cleanup(func() {
		cancel()
		<-done
	})

	res := askControl(t, path, `{"op":"credential","runId":"r1"}`+"\n")
	if res.OK || len(res.Problems) != 1 || !strings.Contains(res.Problems[0], "credential") {
		t.Fatalf("answer = %+v", res)
	}
}

func TestACredentialRequestWaitsForTheBridgeToListenAgain(t *testing.T) {
	path := testSocket(t)
	go func() {
		time.Sleep(300 * time.Millisecond)
		ln, err := listenControl(path)
		if err != nil {
			return
		}
		serveControl(context.Background(), ln, controlOps{credential: func(context.Context, string) credentialResult {
			return credentialResult{OK: true, Login: "u", Token: "late"}
		}})
	}()

	res, err := requestCredential(path, "r1", 5*time.Second, 50*time.Millisecond)
	if err != nil || res.Token != "late" {
		t.Fatalf("answer = %+v, %v", res, err)
	}
}

func TestACredentialRequestGivesUpAfterTheWindow(t *testing.T) {
	path := testSocket(t)
	began := time.Now()
	if _, err := requestCredential(path, "r1", 300*time.Millisecond, 50*time.Millisecond); err == nil {
		t.Fatal("want an error")
	}
	if took := time.Since(began); took > 3*time.Second {
		t.Fatalf("took %v", took)
	}
	// A socket file that nothing listens on is refused, and is retried too.
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	ln, err := net.Listen("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	ln.(*net.UnixListener).SetUnlinkOnClose(false)
	ln.Close()
	if _, err := requestCredential(path, "r1", 300*time.Millisecond, 50*time.Millisecond); !connRefused(err) {
		t.Fatalf("err = %v, want connection refused", err)
	}
}

// credentialCmd runs `loupe credential` with stdin and the given environment.
func credentialCmd(t *testing.T, stdin, runID, sock string, args ...string) (string, error) {
	t.Helper()
	t.Setenv(runIDEnv, runID)
	t.Setenv(controlSocketEnv, sock)
	old := credentialRetryWindow
	credentialRetryWindow = 300 * time.Millisecond
	t.Cleanup(func() { credentialRetryWindow = old })

	var out, errOut bytes.Buffer
	cmd := newCredentialCmd()
	cmd.SetIn(strings.NewReader(stdin))
	cmd.SetOut(&out)
	cmd.SetErr(&errOut)
	cmd.SilenceErrors, cmd.SilenceUsage = true, true
	cmd.SetArgs(args)
	err := cmd.Execute()
	if errOut.Len() > 0 {
		t.Fatalf("stderr = %q", errOut.String())
	}

	return out.String(), err
}

func TestCredentialGetAnswersTheGitProtocol(t *testing.T) {
	path := serveCredentials(t, func(_ context.Context, runID string) credentialResult {
		if runID != "r1" {
			return credentialResult{Problem: "unknown run"}
		}

		return credentialResult{OK: true, Login: "x-access-token", Token: "tok-1"}
	})

	for name, stdin := range map[string]string{
		"full":       "protocol=https\nhost=github.com\npath=acme/site.git\n\n",
		"no host":    "protocol=https\n\n",
		"eof":        "protocol=https\nhost=GitHub.com",
		"crlf":       "protocol=https\r\nhost=github.com\r\n\r\n",
		"after body": "protocol=https\nhost=github.com\n\nignored=1\n",
	} {
		t.Run(name, func(t *testing.T) {
			out, err := credentialCmd(t, stdin, "r1", path, "get")
			if err != nil || out != "username=x-access-token\npassword=tok-1\n\n" {
				t.Fatalf("out = %q, err = %v", out, err)
			}
		})
	}
}

func TestCredentialGetStaysSilentForAnotherHost(t *testing.T) {
	for name, stdin := range map[string]string{
		"gitlab":  "protocol=https\nhost=gitlab.com\n\n",
		"http":    "protocol=http\nhost=github.com\n\n",
		"ssh":     "protocol=ssh\nhost=github.com\n\n",
		"suffix":  "protocol=https\nhost=github.com.evil.example\n\n",
		"port":    "protocol=https\nhost=github.com:8443\n\n",
		"nothing": "",
	} {
		t.Run(name, func(t *testing.T) {
			// The socket does not exist, so any contact with the bridge fails.
			out, err := credentialCmd(t, stdin, "r1", filepath.Join(t.TempDir(), "none.sock"), "get")
			if err != nil || out != "" {
				t.Fatalf("out = %q, err = %v", out, err)
			}
		})
	}
}

func TestCredentialPrintsTheBareToken(t *testing.T) {
	path := serveCredentials(t, func(context.Context, string) credentialResult {
		return credentialResult{OK: true, Login: "x-access-token", Token: "tok-1"}
	})
	out, err := credentialCmd(t, "", "r1", path)
	if err != nil || out != "tok-1\n" {
		t.Fatalf("out = %q, err = %v", out, err)
	}
}

func TestCredentialStoreAndEraseDoNothing(t *testing.T) {
	for _, action := range []string{"store", "erase"} {
		out, err := credentialCmd(t, "protocol=https\nhost=github.com\nusername=a\npassword=b\n\n", "", "", action)
		if err != nil || out != "" {
			t.Fatalf("%s: out = %q, err = %v", action, out, err)
		}
	}
}

func TestCredentialFailsClosed(t *testing.T) {
	refusing := serveCredentials(t, func(context.Context, string) credentialResult {
		return credentialResult{Problem: "no GitHub token for acme/site:\nmissing_grant (contents)"}
	})
	cases := map[string]struct {
		runID, sock, want string
		args              []string
	}{
		"no run id":    {"", refusing, "not a worker", nil},
		"no socket":    {"r1", "", "not a worker", nil},
		"refused":      {"r1", refusing, "missing_grant (contents)", nil},
		"refused get":  {"r1", refusing, "missing_grant (contents)", []string{"get"}},
		"no bridge":    {"r1", filepath.Join(t.TempDir(), "none.sock"), "cannot reach the bridge", nil},
		"unknown verb": {"r1", refusing, "unknown action", []string{"bogus"}},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			out, err := credentialCmd(t, "protocol=https\nhost=github.com\n\n", c.runID, c.sock, c.args...)
			if err == nil || !strings.Contains(err.Error(), c.want) || strings.Contains(err.Error(), "\n") || out != "" {
				t.Fatalf("out = %q, err = %v, want %q", out, err, c.want)
			}
		})
	}
}

// ghFixture builds a folder of fakes: a loupe that prints a token or fails,
// and a gh that prints the token it got.
type ghFixture struct {
	wrapperDir string
	realDir    string
	loupe      string
}

func newGhFixture(t *testing.T, loupeScript string) ghFixture {
	t.Helper()
	root := t.TempDir()
	f := ghFixture{realDir: filepath.Join(root, "real bin"), loupe: filepath.Join(root, "loupe")}
	for _, dir := range []string{f.realDir} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	write := func(path, body string) {
		if err := os.WriteFile(path, []byte("#!/bin/sh\n"+body), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	write(f.loupe, loupeScript)
	write(filepath.Join(f.realDir, "gh"), "echo \"real gh token=$GH_TOKEN args=$*\"\n")
	var err error
	if f.wrapperDir, err = writeGhWrapper(filepath.Join(root, "run"), f.loupe); err != nil {
		t.Fatal(err)
	}

	return f
}

func (f ghFixture) run(t *testing.T, path string, args ...string) (string, string, int) {
	t.Helper()
	cmd := exec.Command(filepath.Join(f.wrapperDir, "gh"), args...)
	cmd.Env = []string{"PATH=" + path, "GH_TOKEN=inherited"}
	var out, errOut bytes.Buffer
	cmd.Stdout, cmd.Stderr = &out, &errOut
	err := cmd.Run()
	code := 0
	if exitErr := (*exec.ExitError)(nil); errors.As(err, &exitErr) {
		code = exitErr.ExitCode()
	} else if err != nil {
		t.Fatal(err)
	}

	return out.String(), errOut.String(), code
}

func TestGhWrapperRunsTheRealGhWithAFreshToken(t *testing.T) {
	f := newGhFixture(t, "[ \"$1\" = credential ] && echo tok-9\n")
	// The wrapper folder comes first on the path of a worker, and holds a gh itself.
	out, errOut, code := f.run(t, f.wrapperDir+":"+f.realDir+":/usr/bin:/bin", "pr", "list", "--json", "a b")
	if code != 0 || out != "real gh token=tok-9 args=pr list --json a b\n" || errOut != "" {
		t.Fatalf("code = %d, out = %q, stderr = %q", code, out, errOut)
	}
	info, err := os.Stat(filepath.Join(f.wrapperDir, "gh"))
	if err != nil || info.Mode().Perm() != 0o700 {
		t.Fatalf("wrapper = %v, %v; want mode 0700", info, err)
	}
}

func TestGhWrapperFailsClosedWhenTheBridgeGivesNoToken(t *testing.T) {
	f := newGhFixture(t, "echo 'bridge says no' >&2\nexit 1\n")
	out, errOut, code := f.run(t, f.wrapperDir+":"+f.realDir+":/usr/bin:/bin", "pr", "list")
	if code != 1 || out != "" || strings.Contains(out, "inherited") || !strings.Contains(errOut, "no GitHub token") {
		t.Fatalf("code = %d, out = %q, stderr = %q", code, out, errOut)
	}
}

func TestGhWrapperStopsWhenNoRealGhExists(t *testing.T) {
	f := newGhFixture(t, "echo tok-9\n")
	out, errOut, code := f.run(t, f.wrapperDir+":/usr/bin:/bin", "pr", "list")
	if code != 127 || out != "" || !strings.Contains(errOut, "gh is not on the PATH") {
		t.Fatalf("code = %d, out = %q, stderr = %q", code, out, errOut)
	}
}

func TestGhWrapperQuotesAnAwkwardLoupePath(t *testing.T) {
	script := ghWrapper("/opt/it's here/loupe", "/runs/a b/bin")
	if !strings.Contains(script, `'/opt/it'\''s here/loupe' credential`) || !strings.Contains(script, `self='/runs/a b/bin'`) {
		t.Fatalf("script = %s", script)
	}
}

func TestRunRecordKeepsTheCredentialFields(t *testing.T) {
	dir := t.TempDir()
	rec := runRecord{PID: 5, RunID: "r1", Dir: "/work", Project: "loupe", Managed: true}
	if err := writeRunRecord(dir, rec); err != nil {
		t.Fatal(err)
	}
	got, err := readRunRecord(dir)
	if err != nil || got.Project != "loupe" || !got.Managed || got.Agent || got.Dir != "/work" {
		t.Fatalf("record = %+v, %v", got, err)
	}
	if err := writeRunRecord(dir, runRecord{PID: 5, RunID: "r1", Agent: true, Project: "loupe"}); err != nil {
		t.Fatal(err)
	}
	if got, _ := readRunRecord(dir); !got.Agent || got.Managed {
		t.Fatalf("record = %+v", got)
	}

	// A record of an older image has no credential field and reads as none.
	old := `{"pid":5,"startedAt":"2026-10-18T12:00:00Z","runId":"r1","dir":"/work","sessionId":"s","prompt":"p"}`
	if err := os.WriteFile(filepath.Join(dir, "run.json"), []byte(old), 0o600); err != nil {
		t.Fatal(err)
	}
	if got, err := readRunRecord(dir); err != nil || got.Project != "" || got.Agent || got.Managed {
		t.Fatalf("record = %+v, %v", got, err)
	}
}

func TestAnAdoptedRunGetsItsCredentialsAgain(t *testing.T) {
	f := newTokenFixture()
	r := &router{credentials: f.broker}
	dir := t.TempDir()

	if err := writeRunRecord(dir, runRecord{PID: 5, RunID: "r1", Dir: "/work", Project: "p1", Managed: true}); err != nil {
		t.Fatal(err)
	}
	forget := r.adoptCredentials(handoverRun{RunID: "r1", Dir: dir})
	if res := f.broker.answer(context.Background(), "r1"); !res.OK {
		t.Fatalf("answer = %+v", res)
	}
	forget()
	if res := f.broker.answer(context.Background(), "r1"); res.OK {
		t.Fatalf("answer = %+v after the run ended", res)
	}

	// A run with neither flag, as an older image or a plain worker writes it.
	if err := writeRunRecord(dir, runRecord{PID: 5, RunID: "r2", Dir: "/work", Project: "p1"}); err != nil {
		t.Fatal(err)
	}
	r.adoptCredentials(handoverRun{RunID: "r2", Dir: dir})
	if res := f.broker.answer(context.Background(), "r2"); res.OK {
		t.Fatalf("answer = %+v for a plain run", res)
	}
}
