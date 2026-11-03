package claude

import (
	"context"
	"errors"
	"github.com/loupe-ac/loupe/cli/internal/envfile"
	"maps"
	"os"
	"os/exec"
	"slices"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/harness"
)

// checkTimeout bounds each claude command of a check.
const checkTimeout = 10 * time.Second

// Check confirms that claude is on PATH in each project and that the account
// is logged in. The bridge gives a worker its skills and the loupe MCP server at
// start, so a check of either would stop a worker that runs. It reads no field
// of auth status, because those name the person.
func (h Harness) Check(ctx context.Context, spec harness.CheckSpec) []harness.Problem {
	env := envfile.Overlay(os.Environ(), spec.Env)
	slugs := slices.Sorted(maps.Keys(spec.Projects))
	// A worker starts in its project folder, which a relative PATH entry
	// counts from, so each project finds its own claude.
	binaries, loginDir := map[string]string{}, ""
	var lookErr error
	for _, slug := range slugs {
		binary, err := envfile.LookPath(h.Program(), env, spec.Projects[slug])
		if err != nil {
			lookErr = err

			continue
		}
		if len(binaries) == 0 {
			loginDir = spec.Projects[slug]
		}
		binaries[slug] = binary
	}
	if len(slugs) == 0 {
		binary, err := envfile.LookPath(h.Program(), env, "")
		if err != nil {
			return []harness.Problem{{Reason: "claude is not on PATH", Detail: err.Error()}}
		}
		binaries[""] = binary
	}
	if len(binaries) == 0 {
		return []harness.Problem{{Reason: "claude is not on PATH", Detail: lookErr.Error()}}
	}
	var problems []harness.Problem
	if _, err := runCheck(ctx, binaries[firstKey(binaries, slugs)], loginDir, env, "auth", "status"); err != nil {
		problems = append(problems, loginProblem(err, spec.ConfigDir))
	}
	for _, slug := range slugs {
		if _, ok := binaries[slug]; !ok {
			problems = append(problems, harness.Problem{Reason: "claude is not on PATH for project " + slug, Detail: "no claude on the PATH of the account from " + spec.Projects[slug]})
		}
	}

	return problems
}

// firstKey is the first slug, in order, that has a binary.
func firstKey(binaries map[string]string, slugs []string) string {
	for _, slug := range slugs {
		if _, ok := binaries[slug]; ok {
			return slug
		}
	}

	return ""
}

// loginProblem tells a login check that ran out of time from a logged-out
// account, because a person fixes the two in different ways.
func loginProblem(err error, configDir string) harness.Problem {
	if errors.Is(err, context.DeadlineExceeded) {
		return harness.Problem{Reason: "login check timed out", Detail: "claude auth status did not answer in " + checkTimeout.String()}
	}

	return harness.Problem{Reason: "not logged in", Detail: loginDetail(configDir)}
}

// runCheck runs one claude command with a timeout, and gives its stdout.
func runCheck(ctx context.Context, binary, dir string, env []string, args ...string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, checkTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, binary, args...)
	cmd.Dir, cmd.Env, cmd.WaitDelay = dir, env, time.Second

	out, err := cmd.Output()
	if err != nil && ctx.Err() != nil {
		err = errors.Join(err, ctx.Err())
	}

	return out, err
}

func loginDetail(configDir string) string {
	if configDir == "" {
		return "run `claude auth login`"
	}

	return "run `CLAUDE_CONFIG_DIR=" + shellQuote(configDir) + " claude auth login`"
}
