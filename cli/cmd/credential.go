package cmd

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
)

const (
	// tokenRefreshWindow is how long before its end the bridge replaces a
	// cached token.
	tokenRefreshWindow = 5 * time.Minute
	// gitTokenTimeout bounds one call for a token, and one git remote read.
	gitTokenTimeout = 30 * time.Second
	// appTokenLogin is the user name git sends with a GitHub App token.
	appTokenLogin = "x-access-token"
)

// credentialResult is the answer of the credential op. Login and Token are set
// when OK is true, and Problem says why not when it is false. It never holds a
// token when OK is false.
type credentialResult struct {
	OK        bool      `json:"ok"`
	Login     string    `json:"login,omitempty"`
	Token     string    `json:"token,omitempty"`
	ExpiresAt time.Time `json:"expiresAt,omitzero"`
	Problem   string    `json:"problem,omitempty"`
}

// credentialRun is a run that the bridge answers credential requests for. A
// run with an agent account has agent set. The other runs are managed, and get
// a GitHub App token of the project.
type credentialRun struct {
	handle string
	dir    string
	agent  bool
}

// tokenKey names one cached token.
type tokenKey struct{ handle, repository string }

// tokenEntry is the cached token of a key. Its lock makes the calls for one
// key run one at a time, so a burst of requests causes one call.
type tokenEntry struct {
	mu      sync.Mutex
	token   string
	expires time.Time
}

// credentialBroker hands the credentials of GitHub to the workers of the bridge.
type credentialBroker struct {
	// issue asks the server for a token. repository reads the repository of a
	// run folder. Tests replace both, and now.
	issue      func(ctx context.Context, handle, repository string) (string, time.Time, error)
	repository func(ctx context.Context, dir string) (string, error)
	now        func() time.Time
	// agent is the stored agent account, or nil.
	agent *config.AgentAccount
	// socket is the control socket that a worker asks.
	socket string

	mu     sync.Mutex
	runs   map[string]credentialRun
	tokens map[tokenKey]*tokenEntry
}

func newCredentialBroker(client *api.Client, agent *config.AgentAccount, socket string) *credentialBroker {
	return &credentialBroker{
		issue: client.IssueGitToken, repository: gitRepository, now: time.Now, agent: agent, socket: socket,
		runs: map[string]credentialRun{}, tokens: map[tokenKey]*tokenEntry{},
	}
}

// register makes the broker answer for the run. The map is made on first use,
// so a test can build a broker as a literal.
func (b *credentialBroker) register(runID string, run credentialRun) {
	b.mu.Lock()
	defer b.mu.Unlock()
	if b.runs == nil {
		b.runs = map[string]credentialRun{}
	}
	b.runs[runID] = run
}

func (b *credentialBroker) forget(runID string) {
	b.mu.Lock()
	defer b.mu.Unlock()
	delete(b.runs, runID)
}

// manages asks for the first token of a run folder, which warms the cache. A
// run is managed when it gets a token and also when the ask fails in any way
// but the two answers that say Loupe does not manage the identity, and a
// folder with no GitHub remote. The helper
// then reports the failure, and the worker never falls back to the git setup
// of the machine. err says why the ask failed. The ask skips the cache, so a
// project setting that changed since the last token decides the new worker.
func (b *credentialBroker) manages(ctx context.Context, handle, dir string) (managed bool, err error) {
	repository, err := b.repository(ctx, dir)
	if err == nil {
		_, _, err = b.fetch(ctx, handle, repository, true)
	}
	if notManaged(err) || errors.Is(err, errNoOrigin) || errors.Is(err, errNotGitHub) {
		return false, err
	}

	return true, err
}

// notManaged says the server answered that Loupe gives no token for the
// project.
func notManaged(err error) bool {
	return errors.Is(err, api.ErrGitIdentityNotManaged) || errors.Is(err, api.ErrGitTokenUnsupported)
}

// token returns the cached token of a project repository, or asks for a new one
// when less than tokenRefreshWindow remains. When the ask fails, a token that
// has not ended yet stays in use, unless the server says it manages the
// identity no more.
func (b *credentialBroker) token(ctx context.Context, handle, repository string) (string, time.Time, error) {
	return b.fetch(ctx, handle, repository, false)
}

// fetch is token, and with fresh it asks the server even for a cached token.
func (b *credentialBroker) fetch(ctx context.Context, handle, repository string, fresh bool) (string, time.Time, error) {
	key := tokenKey{handle: handle, repository: strings.ToLower(repository)}
	b.mu.Lock()
	if b.tokens == nil {
		b.tokens = map[tokenKey]*tokenEntry{}
	}
	entry := b.tokens[key]
	if entry == nil {
		entry = &tokenEntry{}
		b.tokens[key] = entry
	}
	b.mu.Unlock()

	entry.mu.Lock()
	defer entry.mu.Unlock()
	now := b.now()
	if !fresh && entry.token != "" && entry.expires.Sub(now) > tokenRefreshWindow {
		return entry.token, entry.expires, nil
	}
	token, expires, err := b.issue(ctx, handle, repository)
	if notManaged(err) {
		entry.token, entry.expires = "", time.Time{}

		return "", time.Time{}, err
	}
	if err != nil {
		if entry.token != "" && entry.expires.After(now) {
			return entry.token, entry.expires, nil
		}

		return "", time.Time{}, err
	}
	entry.token, entry.expires = token, expires

	return token, expires, nil
}

// answer is the credential op. It answers only for a run it knows.
func (b *credentialBroker) answer(ctx context.Context, runID string) credentialResult {
	b.mu.Lock()
	run, ok := b.runs[runID]
	b.mu.Unlock()
	if !ok {
		return credentialResult{Problem: "the bridge does not hold credentials for this run"}
	}
	if run.agent {
		if b.agent == nil || b.agent.Token == "" {
			return credentialResult{Problem: "the bridge has no agent account"}
		}

		return credentialResult{OK: true, Login: b.agent.Login, Token: b.agent.Token}
	}

	ctx, cancel := context.WithTimeout(ctx, gitTokenTimeout)
	defer cancel()
	repository, err := b.repository(ctx, run.dir)
	if err != nil {
		return credentialResult{Problem: err.Error()}
	}
	token, expires, err := b.token(ctx, run.handle, repository)
	if err != nil {
		return credentialResult{Problem: "no GitHub token for " + repository + ": " + err.Error()}
	}

	return credentialResult{OK: true, Login: appTokenLogin, Token: token, ExpiresAt: expires}
}

var (
	// errNoOrigin and errNotGitHub say the folder has no GitHub remote to push
	// to, so Loupe has no token to give and the machine keeps its git setup.
	errNoOrigin  = errors.New("the folder has no origin remote")
	errNotGitHub = errors.New("the origin remote is not a GitHub https or ssh address")
)

// gitRepository reads owner/name from the origin remote of the git folder dir.
// Every fetch and push address must name one GitHub repository, because git
// pushes to each push address. When only some of them are on GitHub, or they
// name two repositories, the error is neither errNoOrigin nor errNotGitHub, so
// the worker stays managed and fails closed.
func gitRepository(ctx context.Context, dir string) (string, error) {
	fetch, err := originURLs(ctx, dir, "--all")
	if err != nil {
		return "", err
	}
	push, err := originURLs(ctx, dir, "--push", "--all")
	if err != nil {
		return "", err
	}
	remotes := append(fetch, push...)
	repos := make([]string, len(remotes))
	offGitHub := 0
	var firstErr error
	for i, remote := range remotes {
		repos[i], err = parseGitHubRemote(remote)
		if err == nil && hasGitHubSSHPrefix(remote) {
			err = fmt.Errorf("a url rewrite in the git config of the machine sends the origin address %q over SSH, so remove that rewrite", remote)
		}
		if errors.Is(err, errNotGitHub) {
			offGitHub++
		}
		if err != nil && firstErr == nil {
			firstErr = err
		}
	}
	switch {
	case offGitHub == len(remotes):
		return "", firstErr
	case firstErr != nil:
		return "", fmt.Errorf("an origin address: %s", firstErr.Error())
	}
	for _, repo := range repos[1:] {
		if !strings.EqualFold(repo, repos[0]) {
			return "", fmt.Errorf("the origin remote names both %s and %s", repos[0], repo)
		}
	}

	return repos[len(fetch)], nil
}

// originURLs runs git remote get-url for origin in dir, with the url rewrites
// of a worker, and returns one address per line. Only the exit code 2 of git,
// which says the remote does not exist, gives errNoOrigin.
func originURLs(ctx context.Context, dir string, flags ...string) ([]string, error) {
	ctx, cancel := context.WithTimeout(ctx, gitTokenTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, "git", append(append([]string{"remote", "get-url"}, flags...), "origin")...)
	cmd.Dir = dir
	cmd.Env = withGitHubRewrites(os.Environ())
	var stdout, stderr bytes.Buffer
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	err := cmd.Run()
	var exit *exec.ExitError
	if errors.As(err, &exit) && exit.ExitCode() == 2 && ctx.Err() == nil {
		return nil, fmt.Errorf("%w in %s: %s", errNoOrigin, dir, strings.TrimSpace(stderr.String()))
	}
	if err != nil {
		return nil, fmt.Errorf("read the origin remote in %s: %w: %s", dir, err, strings.TrimSpace(stderr.String()))
	}
	urls := strings.Fields(stdout.String())
	if len(urls) == 0 {
		return nil, fmt.Errorf("the origin remote in %s has no address", dir)
	}

	return urls, nil
}

// githubSSHPrefixes are the SSH address forms that workerEnv rewrites to HTTPS.
var githubSSHPrefixes = []string{"git@github.com:", "ssh://git@github.com/"}

// hasGitHubSSHPrefix says whether remote starts with one of githubSSHPrefixes.
// After the rewrites of a worker, only a rewrite of the machine produces one.
func hasGitHubSSHPrefix(remote string) bool {
	return slices.ContainsFunc(githubSSHPrefixes, func(prefix string) bool { return strings.HasPrefix(remote, prefix) })
}

// withGitHubRewrites adds to environ the url rewrites that workerEnv gives a
// worker, after the git config entries that environ holds already.
func withGitHubRewrites(environ []string) []string {
	env := make([]string, 0, len(environ)+5)
	count := 0
	for _, e := range environ {
		if n, ok := strings.CutPrefix(e, "GIT_CONFIG_COUNT="); ok {
			count, _ = strconv.Atoi(n)
			count = max(count, 0)

			continue
		}
		env = append(env, e)
	}
	for _, prefix := range githubSSHPrefixes {
		n := strconv.Itoa(count)
		env = append(env, "GIT_CONFIG_KEY_"+n+"="+githubInsteadOfKey, "GIT_CONFIG_VALUE_"+n+"="+prefix)
		count++
	}

	return append(env, "GIT_CONFIG_COUNT="+strconv.Itoa(count))
}

// parseGitHubRemote returns owner/name from a GitHub remote URL in the https,
// scp or ssh form. A URL with a password is refused, because git pushes with
// that password and never asks the credential helper. So is any other SSH
// address of GitHub, because git would push it with the machine's key.
func parseGitHubRemote(remote string) (string, error) {
	path, ok := "", false
	for _, prefix := range githubSSHPrefixes {
		if path, ok = strings.CutPrefix(remote, prefix); ok {
			break
		}
	}
	if !ok {
		if githubSSHAddress(remote) {
			return "", fmt.Errorf("the origin remote %q is an SSH address that Loupe cannot send through HTTPS, so use https://github.com/<owner>/<name>", remote)
		}
		u, err := url.Parse(remote)
		if err != nil || u.Scheme != "https" || !strings.EqualFold(u.Hostname(), "github.com") {
			return "", fmt.Errorf("%w: %q", errNotGitHub, remote)
		}
		if _, set := u.User.Password(); set {
			return "", fmt.Errorf("the origin remote %s holds a password, so git would not ask Loupe for a token", u.Redacted())
		}
		// The bridge helper is set for https://github.com, which git matches
		// on the default port alone.
		if port := u.Port(); port != "" && port != "443" {
			return "", fmt.Errorf("the origin remote %s names port %s, so git would not ask Loupe for a token", u.Redacted(), port)
		}
		path = u.Path
	}
	path = strings.TrimSuffix(strings.TrimSuffix(path, "/"), ".git")
	path = strings.TrimPrefix(path, "/")
	owner, name, found := strings.Cut(path, "/")
	if !found || owner == "" || name == "" || strings.Contains(name, "/") {
		return "", fmt.Errorf("the origin remote %q does not name a repository as owner/name", remote)
	}

	return owner + "/" + name, nil
}

// githubSSHAddress says whether git reaches remote over SSH on a GitHub host,
// in the URL form or the scp form host:path.
func githubSSHAddress(remote string) bool {
	host := ""
	if scheme, rest, found := strings.Cut(remote, "://"); found {
		if scheme != "ssh" && scheme != "git+ssh" && scheme != "ssh+git" {
			return false
		}
		u, err := url.Parse("ssh://" + rest)
		if err != nil {
			return false
		}
		host = u.Hostname()
	} else if before, _, found := strings.Cut(remote, ":"); found && !strings.Contains(before, "/") {
		host = strings.TrimPrefix(before[strings.LastIndex(before, "@")+1:], "[")
	}

	return strings.EqualFold(host, "github.com") || strings.EqualFold(host, "ssh.github.com")
}

// credentialEnv is what a worker needs to ask the bridge for credentials.
type credentialEnv struct {
	runID  string
	socket string
	// loupe is the absolute path of the running binary, and binDir the folder
	// that holds the gh wrapper of the run.
	loupe  string
	binDir string
}

// shellQuote quotes s as one word of a POSIX shell.
func shellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'"
}

// ghWrapper is the script that stands in for gh in a worker. It gets a fresh
// token for each call and fails closed when it gets none.
func ghWrapper(loupe, binDir string) string {
	return `#!/bin/sh
self=` + shellQuote(binDir) + `
real=
oldifs=$IFS
IFS=:
for d in $PATH; do
  [ -n "$d" ] && [ "$d" != "$self" ] && [ -f "$d/gh" ] && [ -x "$d/gh" ] && { real="$d/gh"; break; }
done
IFS=$oldifs
if [ -z "$real" ]; then
  echo "loupe: gh is not on the PATH" >&2
  exit 127
fi
TOKEN=$(` + shellQuote(loupe) + ` credential) || {
  echo "loupe: no GitHub token for gh, so gh does not run" >&2
  exit 1
}
GH_TOKEN="$TOKEN" exec "$real" "$@"
`
}

// writeGhWrapper writes the gh wrapper into a bin folder of the run directory
// and returns that folder.
func writeGhWrapper(runDir, loupe string) (string, error) {
	binDir := filepath.Join(runDir, "bin")
	if err := os.MkdirAll(binDir, 0o700); err != nil {
		return "", fmt.Errorf("create the gh wrapper folder: %w", err)
	}
	if err := os.WriteFile(filepath.Join(binDir, "gh"), []byte(ghWrapper(loupe, binDir)), 0o700); err != nil {
		return "", fmt.Errorf("write the gh wrapper: %w", err)
	}

	return binDir, nil
}
