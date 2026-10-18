package cmd

import (
	"bytes"
	"context"
	"fmt"
	"log/slog"
	"net/url"
	"os"
	"os/exec"
	"regexp"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

const (
	// identityTTL is the longest time a machine identity stays unchecked.
	identityTTL = 10 * time.Minute
	// identityTimeout bounds one refresh of all projects, and probeTimeout
	// bounds one probe.
	identityTimeout = 15 * time.Second
	probeTimeout    = 5 * time.Second
	// identityParallel is the most projects a refresh probes at once.
	identityParallel = 8

	maxCommitName = 255
	maxPushLogin  = 39
)

// sshGreeting is the line GitHub prints to a successful `ssh -T`.
var sshGreeting = regexp.MustCompile(`Hi ([A-Za-z0-9-]+)!`)

// identityProbes are the reads of the machine identity. Tests replace them.
type identityProbes struct {
	// commitName is `git config user.name` in dir.
	commitName func(ctx context.Context, dir string) string
	// pushURL is the push address of origin in dir, after url rewrites.
	pushURL func(ctx context.Context, dir string) string
	// credential asks the git credential helpers for the token of an https
	// remote, and returns "" when they have none.
	credential func(ctx context.Context, dir string, remote gitRemote) string
	// user asks GitHub who a token belongs to.
	user func(ctx context.Context, token string) (string, int64, error)
	// ssh returns what `ssh -T` printed for the remote.
	ssh func(ctx context.Context, remote gitRemote) string
}

// identityReporter holds the git identity of each followed project. It reads
// the machine and asks the server at start, at a reload and every
// identityTTL, never at a heartbeat. A heartbeat reads the cache.
type identityReporter struct {
	ctx context.Context
	log *slog.Logger
	// agent is the stored agent account. login and agentID are what GitHub
	// gave for its token at the last refresh ("" and 0 when the check failed).
	agent   *config.AgentAccount
	login   string
	agentID int64
	// managed says whether Loupe manages the project, and may be nil.
	managed func(ctx context.Context, handle, dir string) bool
	probes  identityProbes
	now     func() time.Time

	mu       sync.Mutex
	cache    []api.GitIdentityReport
	have     bool
	at       time.Time
	inflight int
	gen      int
	applied  int
}

func newIdentityReporter(ctx context.Context, agent *config.AgentAccount, login string, broker *credentialBroker, log *slog.Logger) *identityReporter {
	r := &identityReporter{ctx: ctx, log: log, agent: agent, login: login, probes: defaultIdentityProbes(), now: time.Now}
	if agent != nil && login != "" {
		r.agentID = agent.ID
	}
	if broker != nil {
		r.managed = func(ctx context.Context, handle, dir string) bool {
			managed, _ := broker.manages(ctx, handle, dir)

			return managed
		}
	}

	return r
}

// identityTarget is one followed project to read.
type identityTarget struct{ id, slug, dir string }

// identityTargets lists the followed projects of set that have an id, at most
// api.MaxGitIdentities of them.
func identityTargets(set *rules.Set) []identityTarget {
	var out []identityTarget
	for _, slug := range set.Projects() {
		if id := set.ProjectID(slug); id != "" && len(out) < api.MaxGitIdentities {
			out = append(out, identityTarget{id: id, slug: slug, dir: set.Dir(slug)})
		}
	}

	return out
}

// start reads the identities of set and returns them. It blocks for at most
// identityTimeout. A nil reporter returns nil.
func (r *identityReporter) start(ctx context.Context, set *rules.Set) []api.GitIdentityReport {
	if r == nil {
		return nil
	}
	targets := identityTargets(set)
	r.refresh(ctx, targets)

	return r.rows(targets)
}

// refreshAsync reads the identities of set in the background.
func (r *identityReporter) refreshAsync(set *rules.Set) {
	if r == nil {
		return
	}
	go r.refresh(r.ctx, identityTargets(set))
}

// rows returns the cached identities of the targets, and nil before the first
// read. A stale cache starts one background refresh and stays in use
// meanwhile.
func (r *identityReporter) rows(targets []identityTarget) []api.GitIdentityReport {
	if r == nil {
		return nil
	}
	r.mu.Lock()
	if !r.have {
		r.mu.Unlock()

		return nil
	}
	stale := r.inflight == 0 && r.now().Sub(r.at) >= identityTTL
	byProject := make(map[string]api.GitIdentityReport, len(r.cache))
	for _, row := range r.cache {
		byProject[row.ProjectID] = row
	}
	if stale {
		r.inflight++
	}
	r.mu.Unlock()
	if stale {
		go r.run(r.ctx, targets)
	}

	out := []api.GitIdentityReport{}
	for _, t := range targets {
		if row, ok := byProject[t.id]; ok {
			out = append(out, row)
		}
	}

	return out
}

// refresh reads the identity of each target, and replaces the cache unless a
// later refresh already did.
func (r *identityReporter) refresh(ctx context.Context, targets []identityTarget) {
	r.mu.Lock()
	r.inflight++
	r.mu.Unlock()
	r.run(ctx, targets)
}

// run does the work of a refresh whose caller counted it in inflight.
func (r *identityReporter) run(ctx context.Context, targets []identityTarget) {
	r.mu.Lock()
	r.gen++
	gen := r.gen
	r.mu.Unlock()

	ctx, cancel := context.WithTimeout(ctx, identityTimeout)
	defer cancel()
	if r.agent != nil {
		login, id := r.checkAgent(ctx)
		r.mu.Lock()
		r.login, r.agentID = login, id
		r.mu.Unlock()
	}
	rows := make([]api.GitIdentityReport, len(targets))
	var wg sync.WaitGroup
	sem := make(chan struct{}, identityParallel)
	for i, t := range targets {
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer func() { <-sem; wg.Done() }()
			rows[i] = r.identity(ctx, t)
		}()
	}
	wg.Wait()

	r.mu.Lock()
	defer r.mu.Unlock()
	r.inflight--
	if gen > r.applied {
		r.applied, r.cache, r.have, r.at = gen, rows, true, r.now()
	}
}

// identity decides the mode of one project and reads its identity.
func (r *identityReporter) identity(ctx context.Context, t identityTarget) api.GitIdentityReport {
	row := api.GitIdentityReport{ProjectID: t.id, Mode: api.GitModeMachine}
	switch {
	case r.agent != nil:
		r.mu.Lock()
		login, id := r.login, r.agentID
		r.mu.Unlock()
		row.Mode = api.GitModeAgentAccount
		row.CommitName = capRunes(r.agent.Login, maxCommitName)
		if login != "" {
			row.PushLogin = login
			if id > 0 {
				row.GitHubUserID = &id
			}
		}
	case r.managed != nil && r.managed(ctx, t.slug, t.dir):
		row.Mode = api.GitModeAppToken
	default:
		r.machine(ctx, t.dir, &row)
	}

	return row
}

// checkAgent asks GitHub who the agent account token belongs to, so a token
// revoked after start stops showing as a verified push account.
func (r *identityReporter) checkAgent(ctx context.Context) (string, int64) {
	login, id, err := r.probes.user(ctx, r.agent.Token)
	if err != nil || !validLogin(login) {
		r.log.Warn("agent_account_check_failed", "login", r.agent.Login, "error", fmt.Sprint(err))

		return "", 0
	}

	return login, id
}

// machine reads what git on this machine commits and pushes as.
func (r *identityReporter) machine(ctx context.Context, dir string, row *api.GitIdentityReport) {
	row.CommitName = capRunes(strings.TrimSpace(r.probes.commitName(ctx, dir)), maxCommitName)
	remote := parseGitRemote(r.probes.pushURL(ctx, dir))
	switch remote.kind {
	case remoteHTTPS:
		token := r.probes.credential(ctx, dir, remote)
		if token == "" {
			return
		}
		login, id, err := r.probes.user(ctx, token)
		if err != nil || !validLogin(login) {
			return
		}
		row.PushLogin = login
		if id > 0 {
			row.GitHubUserID = &id
		}
	case remoteSSH:
		if m := sshGreeting.FindStringSubmatch(r.probes.ssh(ctx, remote)); m != nil && validLogin(m[1]) {
			row.PushLogin = m[1]
		}
	}
}

func validLogin(login string) bool {
	return len(login) <= maxPushLogin && githubLogin.MatchString(login)
}

func capRunes(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}

	return string([]rune(s)[:n])
}

type remoteKind int

const (
	remoteOther remoteKind = iota
	remoteHTTPS
	remoteSSH
)

// gitRemote is a push address that a probe can use. Only GitHub hosts count.
type gitRemote struct {
	kind remoteKind
	host string
	port string
	path string
}

// parseGitRemote classifies a push address. Anything that is not a GitHub
// https or ssh address is remoteOther.
func parseGitRemote(raw string) gitRemote {
	raw = strings.TrimSpace(raw)
	if scheme, _, found := strings.Cut(raw, "://"); found {
		u, err := url.Parse(raw)
		if err != nil {
			return gitRemote{}
		}
		host := strings.ToLower(u.Hostname())
		switch scheme {
		case "https":
			if host == "github.com" && (u.Port() == "" || u.Port() == "443") {
				return gitRemote{kind: remoteHTTPS, host: host, path: strings.TrimPrefix(u.Path, "/")}
			}
		case "ssh", "git+ssh", "ssh+git":
			if githubSSHHost(host) {
				return gitRemote{kind: remoteSSH, host: host, port: u.Port()}
			}
		}

		return gitRemote{}
	}
	if before, _, found := strings.Cut(raw, ":"); found && !strings.Contains(before, "/") {
		host := strings.ToLower(strings.TrimPrefix(before[strings.LastIndex(before, "@")+1:], "["))
		if githubSSHHost(host) {
			return gitRemote{kind: remoteSSH, host: host}
		}
	}

	return gitRemote{}
}

func githubSSHHost(host string) bool {
	return host == "github.com" || host == "ssh.github.com"
}

func defaultIdentityProbes() identityProbes {
	return identityProbes{
		commitName: func(ctx context.Context, dir string) string {
			out, _ := runProbe(ctx, nil, nil, "git", "-C", dir, "config", "user.name")

			return out
		},
		pushURL: func(ctx context.Context, dir string) string {
			out, _ := runProbe(ctx, nil, nil, "git", "-C", dir, "remote", "get-url", "--push", "origin")

			return strings.TrimSpace(out)
		},
		credential: gitCredentialToken,
		user:       githubUser,
		ssh: func(ctx context.Context, remote gitRemote) string {
			args := []string{"-T", "-o", "BatchMode=yes", "-o", "ConnectTimeout=5"}
			if remote.port != "" {
				args = append(args, "-p", remote.port)
			}
			// GitHub prints the greeting on stderr and exits 1.
			_, stderr := runProbe(ctx, nil, nil, "ssh", append(args, "git@"+remote.host)...)

			return stderr
		},
	}
}

// gitCredentialToken asks the credential helpers of the machine for the
// password of an https remote. It passes the question on stdin and reads the
// answer from stdout, so the token never enters an argument. It prompts
// for nothing.
func gitCredentialToken(ctx context.Context, dir string, remote gitRemote) string {
	question := "protocol=https\nhost=" + remote.host + "\n"
	if remote.path != "" {
		question += "path=" + remote.path + "\n"
	}
	env := append(os.Environ(), "GIT_TERMINAL_PROMPT=0", "GCM_INTERACTIVE=never", "GIT_ASKPASS=false")
	out, _ := runProbe(ctx, env, strings.NewReader(question+"\n"), "git", "-C", dir, "credential", "fill")
	for line := range strings.Lines(out) {
		if token, ok := strings.CutPrefix(strings.TrimRight(line, "\r\n"), "password="); ok {
			return token
		}
	}

	return ""
}

// runProbe runs one command under probeTimeout. It returns stdout and stderr,
// and "" for both when the command fails to start.
func runProbe(ctx context.Context, env []string, stdin *strings.Reader, name string, args ...string) (string, string) {
	ctx, cancel := context.WithTimeout(ctx, probeTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.Env = env
	if stdin != nil {
		cmd.Stdin = stdin
	}
	var stdout, stderr bytes.Buffer
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	_ = cmd.Run()

	return stdout.String(), stderr.String()
}
