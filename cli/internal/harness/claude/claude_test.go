package claude

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/harness"
	"github.com/loupe-ac/loupe/cli/internal/stream"
	"github.com/loupe-ac/loupe/cli/internal/transcript"
)

const (
	testSession = "0199a0e2-0000-4000-8000-000000000001"
	ceilingVar  = "CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS="
	connectVar  = "MCP_TIMEOUT="
	sessionVar  = "LOUPE_SESSION_ID="
)

var _ harness.Harness = Harness{}

func ptr[T any](v T) *T { return &v }

func TestTheAdapterIsClaudeCode(t *testing.T) {
	if got := New("").Name(); got != "claude-code" {
		t.Fatalf("Name = %q", got)
	}
	if got := New("").Program(); got != "claude" {
		t.Fatalf("Program = %q", got)
	}
}

// claude -p ends a worker at its background wait ceiling and exits 0. The
// adapter lifts the ceiling unless the operator set it.
func TestWorkerEnvLiftsTheWaitCeiling(t *testing.T) {
	base := make([]string, 2, 5)
	base[0], base[1] = "HOME=/home/a", sessionVar+"s1"

	got := New("").Worker(harness.Spec{SessionID: "s1", Env: base}).Env
	if !slices.Equal(got, []string{"HOME=/home/a", sessionVar + "s1", ceilingVar + "0", connectVar + "150000"}) {
		t.Fatalf("Env = %q", got)
	}
	if extended := base[:5]; extended[2] != "" || extended[3] != "" || extended[4] != "" {
		t.Fatalf("Worker wrote into the caller's array: %q", extended)
	}
	if got := New("").Resume(harness.Spec{SessionID: "s1", Env: base}).Env; !slices.Equal(got, []string{"HOME=/home/a", sessionVar + "s1", ceilingVar + "0", connectVar + "150000"}) {
		t.Fatalf("Resume Env = %q", got)
	}
}

func TestWorkerEnvKeepsTheOperatorsCeiling(t *testing.T) {
	for _, set := range []string{ceilingVar + "5000", ceilingVar + "0", ceilingVar} {
		base := []string{"HOME=/home/a", set, sessionVar + "s1"}
		want := []string{"HOME=/home/a", set, sessionVar + "s1", connectVar + "150000"}
		if got := New("").Worker(harness.Spec{SessionID: "s1", Env: base}).Env; !slices.Equal(got, want) {
			t.Fatalf("Env(%q) = %q", base, got)
		}
	}
}

// claude waits 30 seconds for an MCP server by default, which is less than the
// retries of `loupe mcp` can need. The adapter raises it unless the operator
// set it.
func TestWorkerEnvKeepsTheOperatorsConnectTimeout(t *testing.T) {
	for _, set := range []string{connectVar + "5000", connectVar} {
		base := []string{"HOME=/home/a", set}
		want := []string{"HOME=/home/a", set, ceilingVar + "0"}
		for name, got := range map[string][]string{
			"Worker": New("").Worker(harness.Spec{SessionID: "s1", Env: base}).Env,
			"Resume": New("").Resume(harness.Spec{SessionID: "s1", Env: base}).Env,
		} {
			if !slices.Equal(got, want) {
				t.Fatalf("%s Env(%q) = %q", name, base, got)
			}
		}
	}
}

func TestInteractiveSetsNoConnectTimeout(t *testing.T) {
	script := New("").Interactive("claude", harness.Spec{SessionID: "s1", Dir: "/w", Prompt: "go"})
	if strings.Contains(script, "MCP_TIMEOUT") {
		t.Fatalf("script = %q", script)
	}
}

// A rule's permission mode and model reach claude as flags, and an empty value
// passes none. The session id always follows -p, and the prompt is always the
// last argument.
func TestWorkerArgsCarryTheRulesSettings(t *testing.T) {
	for _, tc := range []struct {
		spec harness.Spec
		want string
	}{
		{harness.Spec{SessionID: testSession, Prompt: "go"}, "--verbose --output-format stream-json -p --session-id " + testSession + " -- go"},
		{harness.Spec{SessionID: testSession, PermissionMode: "plan", Prompt: "go"}, "--permission-mode plan --verbose --output-format stream-json -p --session-id " + testSession + " -- go"},
		{harness.Spec{SessionID: testSession, Model: "opus", Prompt: "go"}, "--model opus --verbose --output-format stream-json -p --session-id " + testSession + " -- go"},
		{harness.Spec{SessionID: testSession, Schema: `{"type":"object"}`, Prompt: "go"}, `--verbose --output-format stream-json --json-schema {"type":"object"} -p --session-id ` + testSession + " -- go"},
		{harness.Spec{SessionID: testSession, Effort: "xhigh", Prompt: "go"}, "--effort xhigh --verbose --output-format stream-json -p --session-id " + testSession + " -- go"},
		{harness.Spec{SessionID: testSession, PermissionMode: "plan", Model: "opus", Effort: "low", Schema: "{}", Prompt: "go"}, "--permission-mode plan --model opus --effort low --verbose --output-format stream-json --json-schema {} -p --session-id " + testSession + " -- go"},
	} {
		if got := strings.Join(New("").Worker(tc.spec).Args, " "); got != tc.want {
			t.Fatalf("Worker(%+v) = %q, want %q", tc.spec, got, tc.want)
		}
	}
}

func TestResumeArgsContinueTheSession(t *testing.T) {
	spec := harness.Spec{SessionID: testSession, PermissionMode: "plan", Model: "opus", Schema: "{}", Prompt: "go"}
	want := "--permission-mode plan --model opus --verbose --output-format stream-json --json-schema {} -p --resume " + testSession + " -- go"
	if got := strings.Join(New("").Resume(spec).Args, " "); got != want {
		t.Fatalf("Resume = %q, want %q", got, want)
	}
}

// claude reads `-p "- x"` as the unknown option "- x". After --, any prompt
// text is the prompt.
func TestAPromptThatLooksLikeAnOptionFollowsTheSeparator(t *testing.T) {
	for _, prompt := range []string{"- x", "--version", "-p"} {
		args := New("").Worker(harness.Spec{Model: "opus", SessionID: testSession, Prompt: prompt}).Args
		if len(args) < 2 || args[len(args)-2] != "--" || args[len(args)-1] != prompt {
			t.Fatalf("Worker(%q) = %q, want the prompt right after --", prompt, args)
		}
	}
}

// The result lines claude -p --output-format stream-json prints. No line, or
// an undecodable one, holds no document.
func TestOutput(t *testing.T) {
	for name, tc := range map[string]struct {
		stdout string
		none   bool
		want   harness.Output
	}{
		"finished": {
			stdout: `{"is_error":false,"result":"Done.","structured_output":{"status":"finished"}}`,
			want:   harness.Output{Decoded: true, Result: "Done.", StructuredOutput: json.RawMessage(`{"status":"finished"}`)},
		},
		"null structured output": {
			stdout: `{"is_error":true,"result":"","structured_output":null}`,
			want:   harness.Output{Decoded: true, StructuredOutput: json.RawMessage(`null`)},
		},
		"broken JSON": {
			stdout: `{"result":"r"`,
		},
		"is_error that is no bool": {
			stdout: `{"result":"r","is_error":"yes","structured_output":{}}`,
			want:   harness.Output{Result: "r"},
		},
		"no result line": {
			none: true,
		},
		"usage": {
			stdout: `{"result":"r","total_cost_usd":0.5,"modelUsage":{"claude-opus-5-5":{"inputTokens":1,"outputTokens":2,"cacheReadInputTokens":3,"cacheCreationInputTokens":4,"costUSD":0.5}}}`,
			want: harness.Output{Decoded: true, Result: "r", Usage: transcript.Usage{
				"claude-opus-5-5": {InputTokens: 1, OutputTokens: 2, CacheReadTokens: 3, CacheWriteTokens: 4, CostUSD: ptr(0.5)},
			}},
		},
		"usage of nothing": {
			stdout: `{"result":"r","modelUsage":{}}`,
			want:   harness.Output{Decoded: true, Result: "r", Usage: transcript.Usage{}},
		},
		"null usage": {
			stdout: `{"result":"r","modelUsage":null}`,
			want:   harness.Output{Decoded: true, Result: "r"},
		},
		"usage that is no object": {
			stdout: `{"result":"r","modelUsage":[]}`,
			want:   harness.Output{Decoded: true, Result: "r"},
		},
	} {
		t.Run(name, func(t *testing.T) {
			var result []byte
			if !tc.none {
				result = []byte(tc.stdout)
			}
			if got := decode(result); !reflect.DeepEqual(got, tc.want) {
				t.Fatalf("Output = %+v, want %+v", got, tc.want)
			}
		})
	}
}

// stubClaude writes a claude that records its directory and its arguments,
// one per NUL, to out.
func stubClaude(t *testing.T, out string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "claude")
	body := "#!/bin/sh\npwd > " + shellQuote(out+".pwd") + "\nprintf '%s\\0' \"$@\" > " + shellQuote(out) + "\n"
	if err := os.WriteFile(path, []byte(body), 0o700); err != nil {
		t.Fatal(err)
	}

	return path
}

// runScript writes the launch script of spec, runs it through /bin/sh, and
// returns the arguments the stub claude read.
func runScript(t *testing.T, spec harness.Spec) []string {
	t.Helper()
	out := filepath.Join(t.TempDir(), "args")
	path := filepath.Join(t.TempDir(), spec.SessionID+".sh")
	if err := os.WriteFile(path, []byte(New("").Interactive(stubClaude(t, out), spec)), 0o700); err != nil {
		t.Fatal(err)
	}
	if b, err := exec.Command("/bin/sh", path).CombinedOutput(); err != nil {
		t.Fatalf("script: %v: %s", err, b)
	}
	if _, err := os.Stat(path); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("the script did not delete itself: %v", err)
	}
	pwd, err := os.ReadFile(out + ".pwd")
	if err != nil {
		t.Fatal(err)
	}
	want, _ := filepath.EvalSymlinks(spec.Dir)
	if got, _ := filepath.EvalSymlinks(strings.TrimSpace(string(pwd))); got != want {
		t.Fatalf("pwd = %q, want %q", got, want)
	}
	raw, err := os.ReadFile(out)
	if err != nil {
		t.Fatal(err)
	}

	return strings.Split(strings.TrimSuffix(string(raw), "\x00"), "\x00")
}

// hard is every character a shell reads as syntax, in single and double
// quotes and bare.
const hard = "it's \"quoted\" $HOME `id` $(id) \\n \\ back\nnew line\n\n%s %d 100% * ? ~ ; & | < > # ! {a,b} [x] '' '\\''"

func TestTheLaunchScriptPassesEachValueAsItIs(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "it's a \"dir\" $x")
	if err := os.Mkdir(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	spec := harness.Spec{Dir: dir, SessionID: "0199a0e2-0000-4000-8000-000000000001", Model: "op'us $x", PermissionMode: "accept`Edits`", Prompt: "Design card 87.\n" + hard}

	got := runScript(t, spec)
	want := []string{"--session-id", spec.SessionID, "--model", spec.Model, "--permission-mode", spec.PermissionMode, "--", spec.Prompt}
	if !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
}

func TestTheLaunchScriptPassesTheEffort(t *testing.T) {
	spec := harness.Spec{Dir: t.TempDir(), SessionID: "s1", Model: "opus", Effort: "high", Prompt: "go"}

	got := runScript(t, spec)
	want := []string{"--session-id", "s1", "--model", "opus", "--effort", "high", "--", "go"}
	if !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
}

func TestTheLaunchScriptLeavesOutAnUnsetModelAndMode(t *testing.T) {
	spec := harness.Spec{Dir: t.TempDir(), SessionID: "s1", Prompt: "-starts with a dash"}

	got := runScript(t, spec)
	if want := []string{"--session-id", "s1", "--", "-starts with a dash"}; !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
}

func TestTheLaunchScriptBody(t *testing.T) {
	spec := harness.Spec{Dir: "/w", SessionID: "s1", Model: "opus", PermissionMode: "plan", Prompt: "go"}
	want := "#!/bin/sh\nrm -f -- \"$0\"\nexport LOUPE_SESSION_ID='s1'\ncd -- '/w' || exit 1\nexec '/bin/claude' --session-id 's1' --model 'opus' --permission-mode 'plan' -- 'go'\n"
	if got := New("").Interactive("/bin/claude", spec); got != want {
		t.Fatalf("Interactive = %q, want %q", got, want)
	}
}

// The script exports the variables of spec.Env, each value as it is.
func TestTheLaunchScriptExportsItsEnvironment(t *testing.T) {
	out := filepath.Join(t.TempDir(), "env")
	program := filepath.Join(t.TempDir(), "claude")
	if err := os.WriteFile(program, []byte("#!/bin/sh\nprintf '%s' \"$LOUPE_TEST_VALUE\" > "+shellQuote(out)+"\n"), 0o700); err != nil {
		t.Fatal(err)
	}
	spec := harness.Spec{Dir: t.TempDir(), SessionID: "s1", Prompt: "go", Env: []string{"LOUPE_TEST_VALUE=" + hard}}
	path := filepath.Join(t.TempDir(), "s1.sh")
	if err := os.WriteFile(path, []byte(New("").Interactive(program, spec)), 0o700); err != nil {
		t.Fatal(err)
	}
	if b, err := exec.Command("/bin/sh", path).CombinedOutput(); err != nil {
		t.Fatalf("script: %v: %s", err, b)
	}
	if got, err := os.ReadFile(out); err != nil || string(got) != hard {
		t.Fatalf("LOUPE_TEST_VALUE = %q, %v", got, err)
	}
}

// A session reads its id from LOUPE_SESSION_ID first, so the script sets it to
// the run id, over a value an env file set.
func TestTheLaunchScriptExportsTheSessionID(t *testing.T) {
	out := filepath.Join(t.TempDir(), "env")
	program := filepath.Join(t.TempDir(), "claude")
	if err := os.WriteFile(program, []byte("#!/bin/sh\nprintf '%s' \"$LOUPE_SESSION_ID\" > "+shellQuote(out)+"\n"), 0o700); err != nil {
		t.Fatal(err)
	}
	spec := harness.Spec{Dir: t.TempDir(), SessionID: testSession, Prompt: "go", Env: []string{sessionVar + "from-file"}}
	path := filepath.Join(t.TempDir(), "s1.sh")
	if err := os.WriteFile(path, []byte(New("").Interactive(program, spec)), 0o700); err != nil {
		t.Fatal(err)
	}
	if b, err := exec.Command("/bin/sh", path).CombinedOutput(); err != nil {
		t.Fatalf("script: %v: %s", err, b)
	}
	if got, err := os.ReadFile(out); err != nil || string(got) != testSession {
		t.Fatalf("LOUPE_SESSION_ID = %q, %v", got, err)
	}
}

func TestTheLaunchScriptBodyWithAnEnvironment(t *testing.T) {
	spec := harness.Spec{Dir: "/w", SessionID: "s1", Prompt: "go", Env: []string{"CLAUDE_CONFIG_DIR=/c"}}
	want := "#!/bin/sh\nrm -f -- \"$0\"\nexport CLAUDE_CONFIG_DIR='/c'\nexport LOUPE_SESSION_ID='s1'\ncd -- '/w' || exit 1\nexec '/bin/claude' --session-id 's1' -- 'go'\n"
	if got := New("").Interactive("/bin/claude", spec); got != want {
		t.Fatalf("Interactive = %q, want %q", got, want)
	}
}

const (
	costState = `{"type":"cost-state","cwd":"/start","modelUsage":{"claude-opus-5-5":{"inputTokens":100,"outputTokens":10,"cacheReadInputTokens":1000,"cacheCreationInputTokens":50,"costUSD":1.25}}}`
	first     = `{"type":"assistant","timestamp":"2099-01-01T00:00:01Z","message":{"id":"m1","model":"claude-opus-5-5","usage":{"input_tokens":10,"output_tokens":1,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}}`
	later     = `{"type":"assistant","timestamp":"2099-01-01T00:00:03Z","message":{"id":"m2","model":"claude-opus-5-5","usage":{"input_tokens":5,"output_tokens":5,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}}`
)

// claudeHome points CLAUDE_CONFIG_DIR at a new directory, and writes the
// transcript lines of session there when lines are given.
func claudeHome(t *testing.T, session string, lines ...string) {
	t.Helper()
	dir := t.TempDir()
	t.Setenv("CLAUDE_CONFIG_DIR", dir)
	if len(lines) == 0 {
		return
	}
	path := filepath.Join(dir, "projects", "-work", session+".jsonl")
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestTheSessionReadsTheTranscript(t *testing.T) {
	claudeHome(t, testSession, costState, first, later)
	h := New("")

	if err := h.HasSession(testSession); err != nil {
		t.Fatalf("HasSession = %v", err)
	}
	if dir, err := h.StartDir(testSession); dir != "/start" || err != nil {
		t.Fatalf("StartDir = %q, %v", dir, err)
	}
	total, err := h.SessionTotal(testSession)
	if want := (transcript.Usage{"claude-opus-5-5": {InputTokens: 100, OutputTokens: 10, CacheReadTokens: 1000, CacheWriteTokens: 50, CostUSD: ptr(1.25)}}); err != nil || !reflect.DeepEqual(total, want) {
		t.Fatalf("SessionTotal = %v, %v", total, err)
	}
	from := time.Date(2099, 1, 1, 0, 0, 2, 0, time.UTC)
	if usage, err := h.SessionUsage(testSession, from, time.Time{}); err != nil || usage["claude-opus-5-5"].InputTokens != 5 {
		t.Fatalf("SessionUsage = %v, %v", usage, err)
	}
	if usage, err := h.SessionUsage(testSession, time.Time{}, from); err != nil || usage["claude-opus-5-5"].InputTokens != 10 {
		t.Fatalf("SessionUsage before %s = %v, %v", from, usage, err)
	}
}

func TestAMissingSessionHasNoTranscript(t *testing.T) {
	claudeHome(t, testSession)
	h := New("")

	if err := h.HasSession(testSession); !errors.Is(err, transcript.ErrNotFound) {
		t.Fatalf("HasSession = %v", err)
	}
	if dir, err := h.StartDir(testSession); dir != "" || err != nil {
		t.Fatalf("StartDir = %q, %v", dir, err)
	}
	if _, err := h.SessionTotal(testSession); err == nil {
		t.Fatal("SessionTotal of a missing session read")
	}
	if _, err := h.SessionUsage(testSession, time.Time{}, time.Time{}); err == nil {
		t.Fatal("SessionUsage of a missing session read")
	}
}

// An adapter with a config folder reads the transcripts there, and not in the
// folder of the bridge's own environment.
func TestTheAdapterReadsItsOwnConfigFolder(t *testing.T) {
	claudeHome(t, testSession, costState)
	own := t.TempDir()

	if err := New(own).HasSession(testSession); !errors.Is(err, transcript.ErrNotFound) {
		t.Fatalf("HasSession in an empty folder = %v", err)
	}
	t.Setenv("CLAUDE_CONFIG_DIR", t.TempDir())
	path := filepath.Join(own, "projects", "-work", testSession+".jsonl")
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(costState+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if dir, err := New(own).StartDir(testSession); dir != "/start" || err != nil {
		t.Fatalf("StartDir = %q, %v", dir, err)
	}
}

// checkClaude puts on PATH a claude that answers auth status with authExit and
// plugin list with plugins, and returns the log of each call: its config
// folder, its folder and its arguments.
func checkClaude(t *testing.T, authExit, plugins string) string {
	t.Helper()
	dir := t.TempDir()
	log, list := filepath.Join(dir, "calls.log"), filepath.Join(dir, "plugins.json")
	if err := os.WriteFile(list, []byte(plugins), 0o600); err != nil {
		t.Fatal(err)
	}
	body := "#!/bin/sh\necho \"$CLAUDE_CONFIG_DIR|$(pwd)|$*\" >> " + shellQuote(log) + "\n" +
		"case \"$1\" in\nauth) exit " + authExit + " ;;\nplugin) cat " + shellQuote(list) + " ;;\nesac\n"
	if err := os.WriteFile(filepath.Join(dir, "claude"), []byte(body), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "loupe"), []byte("#!/bin/sh\n"), 0o700); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", dir+string(os.PathListSeparator)+os.Getenv("PATH"))

	return log
}

// isolate points CLAUDE_CONFIG_DIR and HOME at empty folders, so no check
// reads the configuration of whoever runs the suite.
func isolate(t *testing.T) {
	t.Helper()
	t.Setenv("CLAUDE_CONFIG_DIR", t.TempDir())
	t.Setenv("HOME", t.TempDir())
}

func mkdir(t *testing.T, path string) {
	t.Helper()
	if err := os.MkdirAll(path, 0o700); err != nil {
		t.Fatal(err)
	}
}

func TestCheck(t *testing.T) {
	for name, tc := range map[string]struct {
		authExit string
		want     []harness.Problem
	}{
		"ready with no skills and no server": {authExit: "0"},
		"not logged in":                      {authExit: "1", want: []harness.Problem{{Reason: "not logged in"}}},
	} {
		t.Run(name, func(t *testing.T) {
			isolate(t)
			log := checkClaude(t, tc.authExit, "[]")
			project, config := t.TempDir(), t.TempDir()
			spec := harness.CheckSpec{Account: "a", ConfigDir: config, Env: []string{"CLAUDE_CONFIG_DIR=" + config}, Projects: map[string]string{"loupe": project}}

			got := New(config).Check(context.Background(), spec)
			if len(got) != len(tc.want) {
				t.Fatalf("Check = %+v, want %+v", got, tc.want)
			}
			for i, p := range got {
				if p.Reason != tc.want[i].Reason {
					t.Fatalf("Check = %+v, want %+v", got, tc.want)
				}
				if strings.Contains(p.Reason, "/") {
					t.Fatalf("reason %q names a path", p.Reason)
				}
			}
			calls, err := os.ReadFile(log)
			if err != nil {
				t.Fatal(err)
			}
			if !strings.Contains(string(calls), config+"|") {
				t.Fatalf("claude ran without the account's config folder:\n%s", calls)
			}
			if strings.Contains(string(calls), "plugin") {
				t.Fatalf("the check listed plugins:\n%s", calls)
			}
		})
	}
}

// claude logged out says how to log in with the account's config folder.
func TestCheckNamesTheLoginOfTheConfigFolder(t *testing.T) {
	isolate(t)
	checkClaude(t, "1", "[]")
	config := t.TempDir()
	got := New(config).Check(context.Background(), harness.CheckSpec{ConfigDir: config, Env: []string{"CLAUDE_CONFIG_DIR=" + config}})
	if len(got) != 1 || !strings.Contains(got[0].Detail, "CLAUDE_CONFIG_DIR="+shellQuote(config)+" claude auth login") {
		t.Fatalf("Check = %+v", got)
	}
}

func TestCheckStopsWhenClaudeIsNotOnPath(t *testing.T) {
	isolate(t)
	t.Setenv("PATH", t.TempDir())
	got := New("").Check(context.Background(), harness.CheckSpec{Projects: map[string]string{"loupe": t.TempDir()}})
	if len(got) != 1 || got[0].Reason != "claude is not on PATH" {
		t.Fatalf("Check = %+v", got)
	}
}

func TestALoginCheckThatTimesOutSaysSo(t *testing.T) {
	got := loginProblem(errors.Join(errors.New("signal: killed"), context.DeadlineExceeded), "")
	if got.Reason != "login check timed out" {
		t.Fatalf("Reason = %q, want login check timed out", got.Reason)
	}
	if got := loginProblem(errors.New("exit status 1"), ""); got.Reason != "not logged in" {
		t.Fatalf("Reason = %q, want not logged in", got.Reason)
	}
}

func TestCheckFindsClaudeOnThePathOfTheAccount(t *testing.T) {
	isolate(t)
	checkClaude(t, "0", "[]")
	bin := t.TempDir()
	spec := harness.CheckSpec{Account: "a", Env: []string{"PATH=" + bin}}

	got := New("").Check(context.Background(), spec)
	if len(got) != 1 || got[0].Reason != "claude is not on PATH" {
		t.Fatalf("Check = %+v, want claude is not on PATH", got)
	}
}

func TestCheckFindsClaudeOnARelativePathFromEachProject(t *testing.T) {
	isolate(t)
	fake := filepath.Dir(checkClaude(t, "0", "[]"))
	with, without := t.TempDir(), t.TempDir()
	mkdir(t, filepath.Join(with, "bin"))
	if err := os.Symlink(filepath.Join(fake, "claude"), filepath.Join(with, "bin", "claude")); err != nil {
		t.Fatal(err)
	}
	spec := harness.CheckSpec{Account: "a", Env: []string{"PATH=bin:/bin:/usr/bin"}, Projects: map[string]string{"a": with, "b": without}}

	got := New("").Check(context.Background(), spec)
	if len(got) != 1 || got[0].Reason != "claude is not on PATH for project b" {
		t.Fatalf("Check = %+v, want claude missing for project b alone", got)
	}
}

// ReadRun reads the calls of stdout, and reads none and keeps the error when
// stdout is missing.
func TestReadRunReadsTheCallsOfStdout(t *testing.T) {
	dir := t.TempDir()
	stdout := `{"type":"assistant","timestamp":"2026-10-06T10:00:00.000Z","message":{"usage":{"input_tokens":1,"cache_read_input_tokens":2,"cache_creation_input_tokens":3},"content":[{"type":"tool_use","id":"t1","name":"Bash","input":{"command":"ls"}}]}}` + "\n" +
		`{"type":"user","timestamp":"2026-10-06T10:00:02.000Z","message":{"content":[{"type":"tool_result","tool_use_id":"t1","content":"x"}]}}` + "\n" +
		`{"type":"result","result":"r"}` + "\n"
	if err := os.WriteFile(filepath.Join(dir, "stdout"), []byte(stdout), 0o600); err != nil {
		t.Fatal(err)
	}

	got := New("").ReadRun(dir, harness.RunInfo{})
	if !got.Decoded || got.Result != "r" || !got.CallsRead || got.ReadErr != nil || len(got.Calls) != 1 || got.Calls[0].Kind != stream.KindShell {
		t.Fatalf("ReadRun = %+v", got)
	}
	if got.Timing.ToolTimeMs == nil || *got.Timing.ToolTimeMs != 2000 || got.PeakContextTokens == nil || *got.PeakContextTokens != 6 {
		t.Fatalf("timing %+v, peak %v", got.Timing, got.PeakContextTokens)
	}

	got = New("").ReadRun(t.TempDir(), harness.RunInfo{})
	if got.Decoded || got.CallsRead || got.Calls != nil || got.Timing.ToolTimeMs != nil || got.PeakContextTokens != nil || got.ReadErr == nil {
		t.Fatalf("ReadRun of no stdout = %+v", got)
	}
}

func TestReadRunCountsTheEventsOfTheTranscriptFromTheStartOfTheRun(t *testing.T) {
	claudeHome(t, testSession,
		`{"type":"attachment","timestamp":"2099-01-01T00:00:01Z","attachment":{"type":"diagnostics","files":["/secret/path.go"]}}`,
		`{"type":"attachment","timestamp":"2099-01-01T00:00:05Z","attachment":{"type":"diagnostics"}}`,
		`{"type":"system","timestamp":"2099-01-01T00:00:06Z","subtype":"compact_boundary"}`,
	)
	dir := t.TempDir()
	h := New("")

	all := h.ReadRun(dir, harness.RunInfo{SessionID: testSession})
	if want := map[string]int64{"diagnostics": 2, "compact_boundary": 1}; !reflect.DeepEqual(all.HarnessEvents, want) {
		t.Fatalf("events = %v, want %v", all.HarnessEvents, want)
	}
	since := time.Date(2099, 1, 1, 0, 0, 2, 0, time.UTC)
	late := h.ReadRun(dir, harness.RunInfo{SessionID: testSession, Since: since})
	if want := map[string]int64{"diagnostics": 1, "compact_boundary": 1}; !reflect.DeepEqual(late.HarnessEvents, want) {
		t.Fatalf("events = %v, want %v", late.HarnessEvents, want)
	}
	after := h.ReadRun(dir, harness.RunInfo{SessionID: testSession, Since: since.Add(time.Hour)})
	if after.HarnessEvents == nil || len(after.HarnessEvents) != 0 {
		t.Fatalf("events = %#v, want an empty map", after.HarnessEvents)
	}
}

func TestReadRunLeavesTheEventsNilWithNoTranscript(t *testing.T) {
	claudeHome(t, testSession)
	got := New("").ReadRun(t.TempDir(), harness.RunInfo{SessionID: testSession})
	if got.HarnessEvents != nil {
		t.Fatalf("events = %#v, want nil", got.HarnessEvents)
	}
}

func TestWorkerArgsCarryThePackagesBeforeTheSeparator(t *testing.T) {
	spec := harness.Spec{SessionID: "s", Prompt: "go", PluginDirs: []string{"/store/loupe/1.0.0", "/store/loupe-lifecycle/1.0.0"}, MCPConfig: `{"mcpServers":{}}`}

	got := New("").Worker(spec).Args
	want := []string{"--plugin-dir", "/store/loupe/1.0.0", "--plugin-dir", "/store/loupe-lifecycle/1.0.0", "--mcp-config", `{"mcpServers":{}}`, "-p", "--session-id", "s", "--", "go"}
	if !slices.Equal(got[len(got)-len(want):], want) {
		t.Fatalf("args = %q, want a tail of %q", got, want)
	}
	resumed := New("").Resume(spec).Args
	if !slices.Contains(resumed, "--plugin-dir") {
		t.Fatalf("resume args = %q, want the plugin folders", resumed)
	}
}

func TestWorkerArgsWithNoPackagesPassNoPluginFlag(t *testing.T) {
	got := New("").Worker(harness.Spec{SessionID: "s", Prompt: "go"}).Args
	if slices.Contains(got, "--plugin-dir") || slices.Contains(got, "--mcp-config") {
		t.Fatalf("args = %q, want neither flag", got)
	}
}

func TestTheLaunchScriptPassesThePackages(t *testing.T) {
	body := New("").Interactive("claude", harness.Spec{Dir: "/w", SessionID: "s", Prompt: "go", PluginDirs: []string{"/store/loupe/1.0.0"}, MCPConfig: `{"a":"b'c"}`})
	for _, want := range []string{" '--plugin-dir' '/store/loupe/1.0.0'", ` '--mcp-config' '{"a":"b'\''c"}'`, " -- 'go'"} {
		if !strings.Contains(body, want) {
			t.Fatalf("script lacks %q:\n%s", want, body)
		}
	}
	if strings.Index(body, "--plugin-dir") > strings.Index(body, "' -- '") {
		t.Fatalf("a package flag stands after --:\n%s", body)
	}
}
