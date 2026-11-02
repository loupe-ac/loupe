//go:build unix

package cmd

import (
	"archive/tar"
	"bufio"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/update"
)

const (
	e2eOld = "1.0.0"
	e2eNew = "1.1.0"
	// e2eMajor is a release in the range ^2.0, so a 1.x binary needs it.
	e2eMajor = "2.0.0"
	// e2eWait bounds each wait of the handover tests for the real processes.
	e2eWait = 30 * time.Second
)

// e2eClaude passes the account check at once. As a worker, it records the
// pid of its shell, which leads the worker's process group, and waits for the
// test to release it.
const e2eClaude = `#!/bin/sh
case "$1" in
auth) exit 0 ;;
plugin) echo '[{"id":"loupe@loupe","enabled":true,"mcpServers":{"loupe":{}}}]'; exit 0 ;;
esac
eval "card=\${$#}"; card=${card%%[!0-9]*}
echo $PPID > "$LOUPE_E2E_DIR/started-$card.tmp" && mv "$LOUPE_E2E_DIR/started-$card.tmp" "$LOUPE_E2E_DIR/started-$card"
n=0
while [ ! -e "$LOUPE_E2E_DIR/release" ] && [ $n -lt 600 ]; do sleep 0.1; n=$((n+1)); done
echo "{\"type\":\"result\",\"structured_output\":{\"status\":\"finished\",\"summary\":\"done $card\"}}"
exit "$(cat "$LOUPE_E2E_DIR/exit-$card" 2>/dev/null || echo 0)"
`

// TestHandoverEndToEnd runs two real builds of the CLI. The bridge of the old
// build finds the new one on a fake GitHub and execs it with two workers in
// flight. LOUPE_E2E_OLD and LOUPE_E2E_NEW name prebuilt binaries instead.
// LOUPE_E2E_RELEASED and LOUPE_E2E_RELEASED_VERSION name a build of a real 1.x
// tag, which adds the handover from that build to 2.0.
func TestHandoverEndToEnd(t *testing.T) {
	if testing.Short() {
		t.Skip("runs real builds of the CLI")
	}
	old, next := os.Getenv("LOUPE_E2E_OLD"), os.Getenv("LOUPE_E2E_NEW")
	if old == "" || next == "" {
		if _, err := exec.LookPath("go"); err != nil {
			t.Skip("go is not on PATH, and LOUPE_E2E_OLD and LOUPE_E2E_NEW are not set")
		}
		old, next = buildVersions(t, e2eOld, e2eNew)
	}

	t.Run("forward", func(t *testing.T) { testHandoverForward(t, old, e2eOld, next, e2eNew, false) })
	t.Run("rollback", func(t *testing.T) { testHandoverRollback(t, old, next) })
}

// TestMajorUpdateEndToEnd runs the move from CLI 1.x to 2.0 between real builds:
// the handover of a running bridge, the refusal at start, and the drain.
func TestMajorUpdateEndToEnd(t *testing.T) {
	if testing.Short() {
		t.Skip("runs real builds of the CLI")
	}
	if _, err := exec.LookPath("go"); err != nil {
		t.Skip("go is not on PATH")
	}
	old, major := buildVersions(t, e2eOld, e2eMajor)

	t.Run("handover", func(t *testing.T) { testHandoverForward(t, old, e2eOld, major, e2eMajor, true) })
	t.Run("handover from a real 1.x build", func(t *testing.T) {
		released, from := os.Getenv("LOUPE_E2E_RELEASED"), os.Getenv("LOUPE_E2E_RELEASED_VERSION")
		if released == "" || from == "" {
			t.Skip("LOUPE_E2E_RELEASED and LOUPE_E2E_RELEASED_VERSION are not set")
		}
		testHandoverForward(t, released, from, major, e2eMajor, true)
	})
	t.Run("start updates itself", func(t *testing.T) { testStartUpdatesItself(t, old, major) })
	t.Run("start refuses with auto-update off", func(t *testing.T) { testStartRefuses(t, old, major) })
	t.Run("start in a shared setup names the working command", func(t *testing.T) { testStartInSharedSetup(t, old, major) })
	t.Run("drain and resume", func(t *testing.T) { testDrainAndResume(t, old, major, false) })
	t.Run("drain and resume in a shared setup", func(t *testing.T) { testDrainAndResume(t, old, major, true) })
}

func buildVersions(t *testing.T, older, newer string) (string, string) {
	t.Helper()
	root, err := filepath.Abs("..")
	if err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	paths := []string{filepath.Join(dir, "loupe-"+older), filepath.Join(dir, "loupe-"+newer)}
	errs := make([]error, 2)
	var wg sync.WaitGroup
	for i, v := range []string{older, newer} {
		wg.Go(func() {
			cmd := exec.Command("go", "build", "-o", paths[i], "-ldflags", "-X github.com/loupe-ac/loupe/cli/cmd.version="+v, ".")
			cmd.Dir = root
			cmd.Env = append(os.Environ(), "CGO_ENABLED=0")
			if out, err := cmd.CombinedOutput(); err != nil {
				errs[i] = fmt.Errorf("build %s: %w: %s", v, err, out)
			}
		})
	}
	wg.Wait()
	if err := errors.Join(errs...); err != nil {
		t.Fatal(err)
	}

	return paths[0], paths[1]
}

// e2eLoupe is a Loupe instance, its Mercure hub and GitHub, as far as a
// bridge that updates itself reaches them.
type e2eLoupe struct {
	version     string
	archiveName string
	archive     []byte
	checksums   []byte
	// gate holds the releases list back until the test opens it.
	gate     chan struct{}
	gateOnce sync.Once
	// failBeatsOf answers 500 to each heartbeat of this CLI version.
	failBeatsOf string
	// autoUpdateOff and sharedSetup shape the home of the bridge.
	autoUpdateOff bool
	sharedSetup   bool
	// noRelease answers an empty releases list.
	noRelease bool

	mu sync.Mutex
	// startRange is what /api/versions states, and beatRange is what a
	// heartbeat answer states.
	startRange string
	beatRange  string
	events     []heldEvent
	notify     chan struct{}
	lastIDs    []string
	reports    []api.RunStateReport
	beats      []api.Heartbeat
	unknown    []string
}

func newE2ELoupe(t *testing.T, newBinary, newVersion string) *e2eLoupe {
	t.Helper()
	bin, err := os.ReadFile(newBinary)
	if err != nil {
		t.Fatal(err)
	}
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	if err := tw.WriteHeader(&tar.Header{Name: "loupe", Mode: 0o755, Size: int64(len(bin)), Typeflag: tar.TypeReg}); err != nil {
		t.Fatal(err)
	}
	if _, err := tw.Write(bin); err != nil {
		t.Fatal(err)
	}
	if err := errors.Join(tw.Close(), gz.Close()); err != nil {
		t.Fatal(err)
	}
	v, _ := update.ParseVersion(newVersion)
	f := &e2eLoupe{
		version:     newVersion,
		startRange:  "^1.0",
		beatRange:   "^1.0",
		archiveName: update.AssetName(v, runtime.GOOS, runtime.GOARCH),
		archive:     buf.Bytes(),
		gate:        make(chan struct{}),
		notify:      make(chan struct{}),
	}
	f.checksums = fmt.Appendf(nil, "%x  %s\n", sha256.Sum256(f.archive), f.archiveName)

	return f
}

func (f *e2eLoupe) open() { f.gateOnce.Do(func() { close(f.gate) }) }

// setRanges states what /api/versions and each heartbeat answer say from now on.
func (f *e2eLoupe) setRanges(start, beat string) {
	f.mu.Lock()
	f.startRange, f.beatRange = start, beat
	f.mu.Unlock()
}

// bridgePath turns a path of a released 1.x build into the path of its route
// under /api/v1/bridge, as the server answers both for one release.
func bridgePath(path string) string {
	if !strings.HasPrefix(path, "/api/") || strings.HasPrefix(path, "/api/v1/") || path == "/api/versions" {
		return path
	}

	return "/api/v1/bridge" + strings.Replace(strings.TrimPrefix(path, "/api"), "/board/columns", "/columns", 1)
}

func (f *e2eLoupe) serve(w http.ResponseWriter, r *http.Request) {
	base := "http://" + r.Host
	path := bridgePath(r.URL.Path)
	f.mu.Lock()
	startRange, beatRange := f.startRange, f.beatRange
	f.mu.Unlock()
	switch {
	case path == "/api/versions":
		fmt.Fprintf(w, `{"versions":[{"version":"v1","status":"beta"}],"cliRange":%q}`, startRange)
	case path == "/api/v1/bridge/projects/loupe/columns":
		fmt.Fprintf(w, `{"project":{"id":%q,"slug":"loupe"},"columns":[{"slug":"next"}]}`, testProject)
	case path == "/api/v1/bridge/events":
		fmt.Fprintf(w, `{"hubUrl":%q,"jwt":"jwt","topic":%q,"projects":[{"id":%q,"slug":"loupe","name":"Loupe"}],"flags":{%q:10},"cliRange":%q}`, base+"/hub", userTopic, testProject, api.HeartbeatIntervalFlag, startRange)
	case path == "/hub":
		f.stream(w, r)
	case r.Method == http.MethodPut && path == "/api/v1/bridge/bridges/"+testBridgeID+"/heartbeat":
		var hb api.Heartbeat
		_ = json.NewDecoder(r.Body).Decode(&hb)
		f.mu.Lock()
		f.beats = append(f.beats, hb)
		f.mu.Unlock()
		if hb.CLIVersion == f.failBeatsOf {
			w.WriteHeader(http.StatusInternalServerError)

			return
		}
		fmt.Fprintf(w, `{"cliRange":%q}`, beatRange)
	case r.Method == http.MethodPut && path == "/api/v1/bridge/bridges/"+testBridgeID+"/runs":
		w.WriteHeader(http.StatusNoContent)
	case strings.HasPrefix(path, "/api/v1/bridge/bridges/"+testBridgeID+"/work-requests/"):
		id, action, _ := strings.Cut(strings.TrimPrefix(path, "/api/v1/bridge/bridges/"+testBridgeID+"/work-requests/"), "/")
		req, ok := offeredRequest(id)
		switch {
		case !ok:
			w.WriteHeader(http.StatusNotFound)
		case action == "claim":
			req.State = api.WorkRequestClaimed
			_ = json.NewEncoder(w).Encode(map[string]any{"workRequestId": id, "claimToken": "token-" + id, "leaseUntil": time.Now().Add(2 * time.Minute), "workRequest": req})
		default:
			var body struct {
				State string `json:"state"`
			}
			_ = json.NewDecoder(r.Body).Decode(&body)
			_ = json.NewEncoder(w).Encode(map[string]string{"state": body.State})
		}
	case r.Method == http.MethodPut && strings.HasSuffix(path, "/tool-calls"):
		fmt.Fprint(w, `{"stored":0}`)
	case r.Method == http.MethodPut && strings.Contains(path, "/worker-runs/"):
		var report api.RunStateReport
		_ = json.NewDecoder(r.Body).Decode(&report)
		f.mu.Lock()
		f.reports = append(f.reports, report)
		f.mu.Unlock()
		w.WriteHeader(http.StatusCreated)
	case path == "/github/repos/loupe-ac/loupe/releases":
		select {
		case <-f.gate:
		case <-r.Context().Done():
			return
		}
		if f.noRelease {
			fmt.Fprint(w, `[]`)

			return
		}
		fmt.Fprintf(w, `[{"tag_name":"cli/v%s","assets":[{"name":%q,"browser_download_url":%q},{"name":"checksums.txt","browser_download_url":%q}]}]`,
			f.version, f.archiveName, base+"/github/assets/"+f.archiveName, base+"/github/assets/checksums.txt")
	case path == "/github/assets/"+f.archiveName:
		w.Write(f.archive)
	case path == "/github/assets/checksums.txt":
		w.Write(f.checksums)
	default:
		f.mu.Lock()
		f.unknown = append(f.unknown, r.Method+" "+path)
		f.mu.Unlock()
		w.WriteHeader(http.StatusNotFound)
	}
}

// stream replays the events after Last-Event-ID, as Mercure does, then sends
// each new one until the client leaves.
func (f *e2eLoupe) stream(w http.ResponseWriter, r *http.Request) {
	last := r.Header.Get("Last-Event-ID")
	f.mu.Lock()
	f.lastIDs = append(f.lastIDs, last)
	next := len(f.events)
	for i, e := range f.events {
		if last != "" && e.id == last {
			next = i + 1
		}
	}
	f.mu.Unlock()
	w.Header().Set("Content-Type", "text/event-stream")
	w.WriteHeader(http.StatusOK)
	flusher := w.(http.Flusher)
	flusher.Flush()
	for {
		f.mu.Lock()
		pending, notify := f.events[next:], f.notify
		next = len(f.events)
		f.mu.Unlock()
		for _, e := range pending {
			fmt.Fprintf(w, "id: %s\ndata: %s\n\n", e.id, e.data)
		}
		flusher.Flush()
		select {
		case <-notify:
		case <-r.Context().Done():
			return
		}
	}
}

// push offers a plan work request for the card and returns its event id.
func (f *e2eLoupe) push(card int) string {
	f.mu.Lock()
	defer f.mu.Unlock()
	id := "urn:uuid:event-" + strconv.Itoa(len(f.events)+1)
	f.events = append(f.events, heldEvent{id: id, data: []byte(cardMoved(card))})
	close(f.notify)
	f.notify = make(chan struct{})

	return id
}

func (f *e2eLoupe) connections() []string {
	f.mu.Lock()
	defer f.mu.Unlock()

	return append([]string(nil), f.lastIDs...)
}

// report is the last report of card in state, and whether there is one.
func (f *e2eLoupe) report(card int, states ...string) (api.RunStateReport, bool) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for i := len(f.reports) - 1; i >= 0; i-- {
		rep := f.reports[i]
		for _, s := range states {
			if rep.CardNumber == card && rep.State == s {
				return rep, true
			}
		}
	}

	return api.RunStateReport{}, false
}

// e2eBridge is one bridge process, started from the old binary.
type e2eBridge struct {
	t         *testing.T
	fake      *e2eLoupe
	dir       string
	configDir string
	installed string
	logPath   string
	stdout    string
	rulesPath string
	// setup is the folder of the shared setup, when the bridge runs in one.
	setup  string
	env    []string
	cmd    *exec.Cmd
	exited chan struct{}
}

// startE2EBridge sets up a home with a login and a rule file, a fake claude
// on PATH and the old binary installed, and starts `bridge run`.
func startE2EBridge(t *testing.T, fake *e2eLoupe, old string, env ...string) *e2eBridge {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	t.Cleanup(fake.open)

	// A unix socket path has a length limit, and t.TempDir can pass it.
	home, err := os.MkdirTemp("/tmp", "le2e")
	if err != nil {
		t.Fatal(err)
	}
	t.Setenv("HOME", home)
	t.Setenv("XDG_CONFIG_HOME", filepath.Join(home, ".config"))
	configDir, err := config.Dir()
	if err != nil {
		t.Fatal(err)
	}
	b := &e2eBridge{t: t, fake: fake, dir: filepath.Join(home, "e2e"), configDir: configDir, env: env}
	if fake.sharedSetup {
		b.setup = filepath.Join(home, "setup")
		b.env = append(b.env, config.EnvVar+"="+b.setup)
	}
	for _, d := range []string{configDir, b.dir, filepath.Join(home, "bin"), filepath.Join(home, "work")} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			t.Fatal(err)
		}
	}
	cfg := config.Config{
		BaseURL:  server.URL,
		OAuth:    &config.OAuthTokens{AccessToken: "access", RefreshToken: "refresh", ExpiresAt: time.Now().Add(time.Hour)},
		BridgeID: testBridgeID,
	}
	raw, _ := json.Marshal(cfg)
	rulesPath := filepath.Join(home, "rules.yaml")
	// No resume, so a failed run reports at once rather than after the delay.
	rulesBody := "autoUpdate: " + strconv.FormatBool(!fake.autoUpdateOff) + "\naccounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\n  isolation: none\nprojects:\n  loupe:\n    dir: " + filepath.Join(home, "work") + "\nwork:\n" +
		"  plan:\n    prompt: \"{cardNumber}\"\n"
	b.installed = filepath.Join(home, "bin", "loupe")
	oldBytes, err := os.ReadFile(old)
	if err != nil {
		t.Fatal(err)
	}
	files := map[string][]byte{
		filepath.Join(configDir, "config.json"): raw,
		rulesPath:                               []byte(rulesBody),
		filepath.Join(b.dir, "claude"):          []byte(e2eClaude),
		b.installed:                             oldBytes,
	}
	if b.setup != "" {
		if err := os.MkdirAll(b.setup, 0o700); err != nil {
			t.Fatal(err)
		}
		files[filepath.Join(b.setup, "config.json")] = raw
	}
	for path, body := range files {
		if err := os.WriteFile(path, body, 0o700); err != nil {
			t.Fatal(err)
		}
	}
	t.Setenv("PATH", b.dir+string(os.PathListSeparator)+os.Getenv("PATH"))
	t.Setenv("LOUPE_E2E_DIR", b.dir)
	t.Setenv("LOUPE_UPDATE_API", server.URL+"/github")

	b.logPath = filepath.Join(home, "bridge.log")
	b.stdout = filepath.Join(home, "stdout")
	b.rulesPath = rulesPath
	b.launch()
	t.Cleanup(func() { b.stop(home) })

	return b
}

// launch starts `bridge run` from the installed binary, and appends its output
// to the stdout file.
func (b *e2eBridge) launch() {
	b.t.Helper()
	out, err := os.OpenFile(b.stdout, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		b.t.Fatal(err)
	}
	defer out.Close()
	exited := make(chan struct{})
	cmd := exec.Command(b.installed, "bridge", "run", "--rules", b.rulesPath, "--log-file", b.logPath)
	cmd.Env = append(os.Environ(), b.env...)
	cmd.Stdout, cmd.Stderr = out, out
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	if err := cmd.Start(); err != nil {
		b.t.Fatal(err)
	}
	b.cmd, b.exited = cmd, exited
	go func() {
		_ = cmd.Wait()
		close(exited)
	}()
}

// refused waits for the bridge to exit with a failure, and returns its output.
func (b *e2eBridge) refused() string {
	b.t.Helper()
	select {
	case <-b.exited:
	case <-time.After(e2eWait):
		b.t.Fatalf("the bridge still runs\n%s", b.dump())
	}
	if code := b.cmd.ProcessState.ExitCode(); code == 0 {
		b.t.Fatalf("the bridge exited with 0\n%s", b.dump())
	}
	out, err := os.ReadFile(b.stdout)
	if err != nil {
		b.t.Fatal(err)
	}

	return string(out)
}

// beatState is the update state of the last heartbeat.
func (f *e2eLoupe) beatState() string {
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.beats) == 0 || f.beats[len(f.beats)-1].Update == nil {
		return ""
	}

	return f.beats[len(f.beats)-1].Update.State
}

// stop releases the workers, ends the bridge and kills whatever it left.
func (b *e2eBridge) stop(home string) {
	_ = os.WriteFile(filepath.Join(b.dir, "release"), nil, 0o600)
	pid := b.cmd.Process.Pid
	_ = syscall.Kill(-pid, syscall.SIGTERM)
	select {
	case <-b.exited:
	case <-time.After(15 * time.Second):
		_ = syscall.Kill(-pid, syscall.SIGKILL)
		<-b.exited
	}
	started, _ := filepath.Glob(filepath.Join(b.dir, "started-*"))
	for _, path := range started {
		if raw, err := os.ReadFile(path); err == nil {
			if pgid, err := strconv.Atoi(strings.TrimSpace(string(raw))); err == nil && pgid > 1 {
				_ = syscall.Kill(-pgid, syscall.SIGKILL)
			}
		}
	}
	os.RemoveAll(home)
}

// lines reads the JSON log that both images append to.
func (b *e2eBridge) lines() []map[string]any {
	f, err := os.Open(b.logPath)
	if err != nil {
		return nil
	}
	defer f.Close()
	var out []map[string]any
	sc := bufio.NewScanner(f)
	sc.Buffer(nil, 1<<20)
	for sc.Scan() {
		var line map[string]any
		if json.Unmarshal(sc.Bytes(), &line) == nil {
			out = append(out, line)
		}
	}

	return out
}

// find is the first log line of event from the index from on, and its index.
func (b *e2eBridge) find(event string, from int) (map[string]any, int) {
	lines := b.lines()
	for i := from; i < len(lines); i++ {
		if lines[i]["event"] == event {
			return lines[i], i
		}
	}

	return nil, -1
}

func (b *e2eBridge) dump() string {
	log, _ := os.ReadFile(b.logPath)
	out, _ := os.ReadFile(b.stdout)
	b.fake.mu.Lock()
	defer b.fake.mu.Unlock()

	return fmt.Sprintf("log:\n%s\nstdout:\n%s\nunknown requests: %v\nhub Last-Event-IDs: %q", log, out, b.fake.unknown, b.fake.lastIDs)
}

// wait polls ok, and fails when the bridge exits first.
func (b *e2eBridge) wait(what string, ok func() bool) {
	b.t.Helper()
	deadline := time.After(e2eWait)
	for !ok() {
		select {
		case <-b.exited:
			if ok() {
				return
			}
			b.t.Fatalf("the bridge exited while waiting for %s\n%s", what, b.dump())
		case <-deadline:
			b.t.Fatalf("timed out waiting for %s\n%s", what, b.dump())
		case <-time.After(50 * time.Millisecond):
		}
	}
}

func (b *e2eBridge) waitEvent(event string) map[string]any {
	b.t.Helper()
	var line map[string]any
	b.wait(event, func() bool {
		line, _ = b.find(event, 0)

		return line != nil
	})

	return line
}

// startTwoWorkers pushes two cards and waits until both workers run. Card 2
// exits with 3. It returns the id of the last event.
func (b *e2eBridge) startTwoWorkers() string {
	b.t.Helper()
	if err := os.WriteFile(filepath.Join(b.dir, "exit-2"), []byte("3\n"), 0o600); err != nil {
		b.t.Fatal(err)
	}
	b.wait("the first hub connection", func() bool { return len(b.fake.connections()) == 1 })
	b.fake.push(1)
	last := b.fake.push(2)
	b.wait("two running workers", func() bool {
		_, one := b.fake.report(1, api.RunRunning)
		_, two := b.fake.report(2, api.RunRunning)

		return one && two
	})

	return last
}

// release ends the fake workers, and checks the final report of each card.
func (b *e2eBridge) release(codes map[int]int) {
	b.t.Helper()
	if err := os.WriteFile(filepath.Join(b.dir, "release"), nil, 0o600); err != nil {
		b.t.Fatal(err)
	}
	for card, code := range codes {
		want := api.RunSucceeded
		if code != 0 {
			want = api.RunFailed
		}
		var rep api.RunStateReport
		b.wait(fmt.Sprintf("the final report of card %d", card), func() bool {
			var ok bool
			rep, ok = b.fake.report(card, api.RunSucceeded, api.RunFailed, api.RunNoResult, api.RunNotStarted)

			return ok
		})
		if rep.State != want || rep.ExitCode == nil || *rep.ExitCode != code || rep.HasResult == nil || !*rep.HasResult ||
			rep.Output != fmt.Sprintf("done %d", card) {
			b.t.Fatalf("card %d: final report = %+v, want %s with exit %d and its result line\n%s", card, rep, want, code, b.dump())
		}
	}
}

// same checks that the bridge still runs in the process the test started.
func (b *e2eBridge) same(start string) {
	b.t.Helper()
	select {
	case <-b.exited:
		b.t.Fatalf("the bridge process exited\n%s", b.dump())
	default:
	}
	if got := processStart(b.cmd.Process.Pid); got != start {
		b.t.Fatalf("pid %d started at %q, and now at %q", b.cmd.Process.Pid, start, got)
	}
}

func (b *e2eBridge) noHandoverFile() {
	b.t.Helper()
	for _, pattern := range []string{"handover-*", ".handover-*"} {
		left, _ := filepath.Glob(filepath.Join(b.configDir, pattern))
		if len(left) > 0 {
			b.t.Fatalf("handover files remain: %v", left)
		}
	}
}

func sameBytes(t *testing.T, path, want string) bool {
	t.Helper()
	have, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	wanted, err := os.ReadFile(want)
	if err != nil {
		t.Fatal(err)
	}

	return bytes.Equal(have, wanted)
}

// testHandoverForward hands a bridge with two workers over from one build to the
// next. With major set, the server moves its range to ^2.0 while the bridge runs,
// as it does when an instance upgrades under a running 1.x bridge.
func testHandoverForward(t *testing.T, old, from, next, to string, major bool) {
	fake := newE2ELoupe(t, next, to)
	b := startE2EBridge(t, fake, old)
	start := processStart(b.cmd.Process.Pid)
	last := b.startTwoWorkers()

	if major {
		fake.setRanges("^2.0", "^2.0")
	}
	fake.open()
	applied := b.waitEvent("update_applied")
	handover, at := b.find("update_handover", 0)
	if _, appliedAt := b.find("update_applied", 0); handover == nil || at > appliedAt {
		t.Fatalf("update_handover must come before update_applied\n%s", b.dump())
	}
	if handover["from"] != from || handover["to"] != to || handover["live"] != float64(2) {
		t.Fatalf("update_handover = %v", handover)
	}
	if applied["from"] != from || applied["to"] != to {
		t.Fatalf("update_applied = %v", applied)
	}
	b.same(start)
	b.wait("the hub reconnect", func() bool { return len(fake.connections()) >= 2 })
	if conns := fake.connections(); len(conns) != 2 || conns[0] != "" || conns[1] != last {
		t.Fatalf("hub Last-Event-IDs = %q, want the new image to resume from %q", conns, last)
	}

	fake.push(3)
	b.wait("a worker for the card pushed after the handover", func() bool {
		_, ok := fake.report(3, api.RunRunning)

		return ok
	})
	b.release(map[int]int{1: 0, 2: 3, 3: 0})

	b.waitEvent("update_installed")
	if !sameBytes(t, b.installed, next) {
		t.Fatalf("%s does not hold the new build\n%s", b.installed, b.dump())
	}
	b.same(start)
	b.noHandoverFile()
}

func testHandoverRollback(t *testing.T, old, next string) {
	fake := newE2ELoupe(t, next, e2eNew)
	fake.failBeatsOf = e2eNew
	b := startE2EBridge(t, fake, old, "LOUPE_HEALTH_TIMEOUT=2s")
	start := processStart(b.cmd.Process.Pid)
	last := b.startTwoWorkers()

	fake.open()
	back := b.waitEvent("update_rolled_back")
	if back["from"] != e2eNew || back["to"] != e2eOld || back["reason"] != "health" {
		t.Fatalf("update_rolled_back = %v\n%s", back, b.dump())
	}
	if line, _ := b.find("update_unhealthy", 0); line == nil {
		t.Fatalf("no update_unhealthy\n%s", b.dump())
	}
	if line, _ := b.find("update_applied", 0); line != nil {
		t.Fatalf("update_applied = %v", line)
	}
	b.same(start)
	st, err := update.LoadState(b.configDir)
	if err != nil {
		t.Fatal(err)
	}
	if !st.Skipped(e2eNew) {
		t.Fatalf("the skip list = %v, want %s on it", st.Skips, e2eNew)
	}
	if conns := fake.connections(); len(conns) < 2 || conns[len(conns)-1] != last {
		t.Fatalf("hub Last-Event-IDs = %q, want each image to resume from %q", conns, last)
	}

	b.release(map[int]int{1: 0, 2: 3})
	if !sameBytes(t, b.installed, old) {
		t.Fatalf("%s no longer holds the old build", b.installed)
	}
	b.same(start)
	b.noHandoverFile()
}

func testStartUpdatesItself(t *testing.T, old, major string) {
	fake := newE2ELoupe(t, major, e2eMajor)
	fake.setRanges("^2.0", "^2.0")
	fake.open()
	b := startE2EBridge(t, fake, old)

	if out := b.refused(); !strings.Contains(out, "start the bridge again") {
		t.Fatalf("output: %s", out)
	}
	if !sameBytes(t, b.installed, major) {
		t.Fatalf("%s does not hold the new build\n%s", b.installed, b.dump())
	}

	b.launch()
	b.wait("the first hub connection", func() bool { return len(fake.connections()) == 1 })
}

func testStartRefuses(t *testing.T, old, major string) {
	fake := newE2ELoupe(t, major, e2eMajor)
	fake.autoUpdateOff = true
	fake.setRanges("^2.0", "^2.0")
	fake.open()
	b := startE2EBridge(t, fake, old)

	if out := b.refused(); !strings.Contains(out, "Run: loupe update") {
		t.Fatalf("output: %s", out)
	}
	if !sameBytes(t, b.installed, old) {
		t.Fatalf("%s no longer holds the old build", b.installed)
	}
}

// testStartInSharedSetup runs the command of the refusal, and proves that it
// replaces the shared binary, so the bridge of the setup starts.
func testStartInSharedSetup(t *testing.T, old, major string) {
	fake := newE2ELoupe(t, major, e2eMajor)
	fake.sharedSetup = true
	fake.setRanges("^2.0", "^2.0")
	fake.open()
	b := startE2EBridge(t, fake, old)

	const fix = "Run: LOUPE_CONFIG= loupe update"
	if out := b.refused(); !strings.Contains(out, fix) {
		t.Fatalf("output: %s", out)
	}
	if !sameBytes(t, b.installed, old) {
		t.Fatalf("the refusal replaced %s", b.installed)
	}

	cmd := exec.Command(b.installed, "update")
	cmd.Env = append(os.Environ(), config.EnvVar+"=")
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("%s: %v: %s", fix, err, out)
	}
	if !sameBytes(t, b.installed, major) {
		t.Fatalf("%s does not hold the new build\n%s", b.installed, b.dump())
	}

	b.launch()
	b.wait("the first hub connection", func() bool { return len(fake.connections()) == 1 })
}

// testDrainAndResume moves the range away from a running bridge when no release
// fits. The bridge holds a new card back, keeps its heartbeat, and starts the
// card when the range comes back.
func testDrainAndResume(t *testing.T, old, major string, shared bool) {
	fake := newE2ELoupe(t, major, e2eMajor)
	fake.noRelease = true
	fake.sharedSetup = shared
	fake.setRanges("^1.0", "^2.0")
	fake.open()
	b := startE2EBridge(t, fake, old)
	start := processStart(b.cmd.Process.Pid)

	b.waitEvent("bridge_draining")
	b.wait("a heartbeat that states the drain", func() bool { return fake.beatState() == updateDraining })
	b.wait("the first hub connection", func() bool { return len(fake.connections()) == 1 })
	fake.push(1)
	time.Sleep(2 * time.Second)
	if _, started := fake.report(1, api.RunRunning); started {
		t.Fatalf("a draining bridge started a worker\n%s", b.dump())
	}

	fake.setRanges("^1.0", "^1.0")
	b.waitEvent("bridge_resumed")
	b.wait("the worker of the held card", func() bool {
		_, started := fake.report(1, api.RunRunning)

		return started
	})
	b.same(start)
	if !sameBytes(t, b.installed, old) {
		t.Fatalf("%s no longer holds the old build", b.installed)
	}
}
