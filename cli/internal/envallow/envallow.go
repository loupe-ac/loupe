// Package envallow builds the environment of a bridge worker from an
// allowlist. A pattern is an exact name, or a prefix that ends in "*".
package envallow

import (
	"os"
	"slices"
	"strings"
	"sync"
)

// Harness names that have their own entries in the base list.
const (
	HarnessClaude = "claude-code"
	HarnessCodex  = "codex"
)

// FilteredMarker is set in the environment of a bridge that already restarted
// on the filtered environment, so it restarts only once.
const FilteredMarker = "LOUPE_BRIDGE_ENV_FILTERED"

var common = []string{
	"PATH", "HOME", "USER", "LOGNAME", "SHELL", "TMPDIR", "TERM", "COLORTERM", "NO_COLOR",
	"LANG", "LC_*", "TZ", "XDG_*", "__CF_USER_TEXT_ENCODING",
	"HTTP_PROXY", "HTTPS_PROXY", "NO_PROXY", "ALL_PROXY",
	"http_proxy", "https_proxy", "no_proxy", "all_proxy",
	"SSL_CERT_FILE", "SSL_CERT_DIR", "NODE_EXTRA_CA_CERTS",
	"DOCKER_HOST", "DOCKER_CONTEXT", "DOCKER_CONFIG",
	"HOMEBREW_PREFIX", "HOMEBREW_CELLAR", "HOMEBREW_REPOSITORY",
	"GIT_AUTHOR_NAME", "GIT_AUTHOR_EMAIL", "GIT_COMMITTER_NAME", "GIT_COMMITTER_EMAIL",
	"SSH_AUTH_SOCK",
	"LOUPE_CONFIG", "LOUPE_URL",
}

var claudeNames = []string{"ANTHROPIC_*", "CLAUDE_*", "MCP_TIMEOUT"}

var codexNames = []string{"OPENAI_*", "CODEX_*"}

// Base gives the patterns that a worker of the harness always gets.
func Base(harness string) []string {
	out := slices.Clone(common)
	switch harness {
	case HarnessClaude:
		out = append(out, claudeNames...)
	case HarnessCodex:
		out = append(out, codexNames...)
	default:
		out = append(out, claudeNames...)
		out = append(out, codexNames...)
	}

	return out
}

// bridgeOnly are the variables that only the bridge process reads.
var bridgeOnly = []string{"LOUPE_UPDATE_API", "LOUPE_HEALTH_TIMEOUT", "LOUPE_ROTATE_*"}

// Union gives the patterns the bridge process itself keeps: every harness list
// and the variables that only the bridge reads.
func Union() []string {
	return append(Base(""), bridgeOnly...)
}

// Match says whether name matches one of patterns.
func Match(name string, patterns []string) bool {
	for _, p := range patterns {
		if prefix, ok := strings.CutSuffix(p, "*"); ok {
			if strings.HasPrefix(name, prefix) {
				return true
			}

			continue
		}
		if name == p {
			return true
		}
	}

	return false
}

func nameOf(entry string) string {
	name, _, _ := strings.Cut(entry, "=")

	return name
}

// Filter keeps the entries of environ whose name matches a pattern.
func Filter(environ, patterns []string) []string {
	out := make([]string, 0, len(environ))
	for _, e := range environ {
		if Match(nameOf(e), patterns) {
			out = append(out, e)
		}
	}

	return out
}

// Dropped gives the sorted names of environ that no pattern matches. It
// never returns a value.
func Dropped(environ, patterns []string) []string {
	var out []string
	for _, e := range environ {
		if name := nameOf(e); !Match(name, patterns) && !slices.Contains(out, name) {
			out = append(out, name)
		}
	}
	slices.Sort(out)

	return out
}

var (
	mu   sync.RWMutex
	pass []string
)

// SetPass sets the names that the rule file adds to every base list.
func SetPass(names []string) {
	mu.Lock()
	defer mu.Unlock()
	pass = slices.Clone(names)
}

// Pass gives the names that the rule file adds.
func Pass() []string {
	mu.RLock()
	defer mu.RUnlock()

	return slices.Clone(pass)
}

// Patterns gives the base list of the harness plus the pass names.
func Patterns(harness string) []string {
	return append(Base(harness), Pass()...)
}

// Environ gives the environment of this process, filtered for the harness.
func Environ(harness string) []string {
	return Filter(os.Environ(), Patterns(harness))
}

// DroppedFor gives the names of this process that a worker of the harness
// does not get.
func DroppedFor(harness string) []string {
	return Dropped(os.Environ(), Patterns(harness))
}
