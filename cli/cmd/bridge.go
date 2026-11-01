package cmd

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"maps"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"runtime"
	"slices"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/hooks"
	"github.com/loupe-ac/loupe/cli/internal/logfile"
	"github.com/loupe-ac/loupe/cli/internal/outbound"
	"github.com/loupe-ac/loupe/cli/internal/rules"
	"github.com/loupe-ac/loupe/cli/internal/setupbuild"
	"github.com/loupe-ac/loupe/cli/internal/transport"
	"github.com/loupe-ac/loupe/cli/internal/update"
	"github.com/loupe-ac/loupe/cli/internal/usage"
	"github.com/spf13/cobra"
)

// refreshTimeout bounds a credentials fetch. http.DefaultClient has none at
// all, so a single unanswered request would stall reconnection for good — the
// bridge would sit there looking healthy and never receive anything again.
const refreshTimeout = 15 * time.Second

// apiClient is for short request/response calls only. The SSE subscription must
// keep its own timeout-free client: a stream is meant to stay open.
// bridgeReach records the failed requests of every API client of the process,
// for the bridge to tell a network outage from another failure.
var bridgeReach = &reachTracker{}

func apiClient(cfg config.Config) *api.Client {
	hc := &http.Client{Timeout: refreshTimeout, Transport: reachTransport{base: withUserAgent(nil), tracker: bridgeReach}}

	var tokens api.TokenSource = tokenSource(cfg, hc)
	if g := currentBridgeGate(); g != nil {
		tokens = gatedSource{inner: tokens, gate: g}
	}

	return api.NewWithSource(cfg.BaseURL, tokens, hc)
}

func newBridgeCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "bridge",
		Short: "Run Loupe work requests in local workers",
	}
	cmd.AddCommand(newBridgeRunCmd(), newBridgeReloadCmd(), newBridgeStopCmd(), newBridgeStatusCmd(), newBridgePreflightCmd(), newBridgeHooksCmd(), newBridgeLogsCmd())

	return cmd
}

// bridgeRunOptions are the flags of `loupe bridge run`.
type bridgeRunOptions struct {
	rulesPath, permissionMode, model, logFile string
	// resumeFile and rolledBackFrom carry a handover from a former image.
	resumeFile, rolledBackFrom string
	// daemon starts the bridge detached and returns.
	daemon bool
}

func newBridgeRunCmd() *cobra.Command {
	var o bridgeRunOptions

	cmd := &cobra.Command{
		Use:   "run",
		Short: "Claim the work requests of the workflow and run an agent for each one",
		Long: "Reads the rule file, rules.yaml in your config directory. The workflow of a board " +
			"decides when work runs and opens a work request for each piece of work. The bridge " +
			"claims each request and runs the prompt for its kind of work: the entry of the " +
			"work map for that kind, or the prompt that Loupe sends when the file sets " +
			"appPrompts: true. A worker is `claude -p --session-id <uuid> -- <prompt>`. " +
			"The projects map in the file gives each project the directory its workers run in.\n\n" +
			"The bridge refuses to start without the file, and checks every project slug " +
			"against the server first. It follows every project you own on " +
			"one connection, and ignores the work requests of a project the file does not map.\n\n" +
			"With -d the bridge runs in the background, and its log goes to the log file. " +
			"Run `loupe bridge stop` to stop it.\n\n" +
			"Run `loupe bridge reload` to apply a changed rule file without a restart. " +
			"A second bridge on the same rule file refuses to start.\n\n" +
			"The defaults block of the rule file and each work entry win over --permission-mode " +
			"and --model. The flags fill a value that both leave empty. A worker has no " +
			"terminal, so it cannot answer a permission prompt: with no mode, claude " +
			"denies every tool call that needs approval.\n\n" +
			"Set maxWorkers in the rule file to bound the workers that run at once. Requests past the " +
			"bound wait in a queue and start in arrival order, except that a request waits " +
			"while its card has a worker. The bridge writes one JSON " +
			"object per line to stdout and to --log-file.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			dropInheritedSession()
			if o.daemon {
				return startDaemon(cmd, o)
			}

			return startBridge(cmd, o)
		},
	}
	cmd.Flags().StringVar(&o.rulesPath, "rules", "", "read rules from this `path`; empty uses rules.yaml in your config directory")
	cmd.Flags().StringVar(&o.permissionMode, "permission-mode", "", "pass this `mode` to every `claude` when neither its work entry nor the defaults block of the rule file sets one; empty passes no flag, and a worker cannot answer a prompt")
	cmd.Flags().StringVar(&o.model, "model", "", "pass this `model` to every `claude` when neither its work entry nor the defaults block of the rule file sets one; empty passes no flag")
	cmd.Flags().Int(maxWorkersFlag, rules.DefaultMaxWorkers, "does nothing; set maxWorkers in rules.yaml")
	cmd.Flags().MarkDeprecated(maxWorkersFlag, "it does nothing; set maxWorkers in rules.yaml instead")
	cmd.Flags().StringVar(&o.logFile, "log-file", "", "append the JSON log to this `path`; empty uses bridge.log in your config directory")
	cmd.Flags().BoolVarP(&o.daemon, daemonFlag, "d", false, "start the bridge in the background and return; `loupe bridge stop` stops it")
	cmd.Flags().StringVar(&o.resumeFile, resumeHandoverFlag, "", "take over the bridge that a former image handed over in this `file`")
	cmd.Flags().StringVar(&o.rolledBackFrom, rolledBackFromFlag, "", "the `version` that handed the bridge back")
	cmd.Flags().MarkHidden(resumeHandoverFlag)
	cmd.Flags().MarkHidden(rolledBackFromFlag)

	return cmd
}

// bridgeLog is the log of a bridge and the file it appends to.
type bridgeLog struct {
	path string
	file *logfile.Writer
	log  *slog.Logger
	last *lastErrorTracker
	// name is the bridge name that each entry carries.
	name *bridgeLogName
}

// openBridgeLog names the bridge from the rule file before the first entry,
// because the start can log before the rule file validates.
func openBridgeLog(cmd *cobra.Command, logFile, rulesPath string) (*bridgeLog, error) {
	path := logFile
	if path == "" {
		path = defaultLogPath()
	}
	f, err := logfile.Open(path)
	if err != nil {
		return nil, err
	}
	name := &bridgeLogName{}
	name.set(logName(rules.NameOf(rulesPath)))

	log, last := newNamedTrackedLogger(bridgeLogWriter(f, cmd.OutOrStdout()), name)

	return &bridgeLog{path: path, file: f, name: name, log: log, last: last}, nil
}

func startBridge(cmd *cobra.Command, o bridgeRunOptions) error {
	defaults := rules.Defaults{PermissionMode: o.permissionMode, Model: o.model}
	if err := defaults.Check(); err != nil {
		return err
	}
	path, err := rulesPathOr(o.rulesPath)
	if err != nil {
		return err
	}
	pin, err := makeSetupPin()
	if err != nil {
		return err
	}
	if pin != "" {
		setupbuild.PrunePins(config.Current().Dir, pin)
	}
	if o.resumeFile == "" {
		return runBridgeOn(cmd, o, defaults, path, nil, nil, nil)
	}

	// A resumed image logs from the start, because a failure before the
	// health hands the bridge back, and the operator reads why in the log.
	bl, err := openBridgeLog(cmd, o.logFile, path)
	if err != nil {
		return err
	}
	defer bl.file.Close()
	sock, err := socketPath(path)
	if err != nil {
		return err
	}
	b, control, err := resumeBridge(bl.log, path, sock, o.resumeFile, o.rolledBackFrom)
	if err != nil {
		bl.log.Error("update_resume_failed", "file", o.resumeFile, "error", err.Error())

		return err
	}
	err = runBridgeOn(cmd, o, defaults, path, bl, b, control)
	if err != nil {
		b.rollbackAtStart(err)
	}
	b.dropResumeFile()
	control.Close()
	b.close()
	b.lock.Close()

	return err
}

// runBridgeOn starts the bridge. A resumed image passes its log, its handover
// and the control socket it took over. Otherwise it takes the lock and the
// socket itself.
func runBridgeOn(cmd *cobra.Command, o bridgeRunOptions, defaults rules.Defaults, path string, bl *bridgeLog, b *bridgeUpdate, control net.Listener) error {
	migration := migrateAccounts(path)
	if bl == nil && migration.notable() {
		// The start can stop before its log opens, and the next start finds nothing to log.
		var err error
		if bl, err = openBridgeLog(cmd, o.logFile, path); err != nil {
			return err
		}
		defer bl.file.Close()
	}
	if bl != nil {
		migration.log(bl.log, path)
	}
	set, err := rules.Load(path, defaults)
	if err != nil {
		return fmt.Errorf("rule file %s: %w", path, err)
	}
	hookList, err := resolveHooks(set)
	if err != nil {
		return fmt.Errorf("rule file %s: %w", path, err)
	}
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	if err := set.Check(cmd.Context(), apiClient(cfg)); err != nil {
		return fmt.Errorf("rule file %s: %w", path, err)
	}
	// A bridge with no stored credentials reaches neither the stream nor
	// the reporting endpoints, so it stops here rather than starting a
	// worker whose run it can report nothing about.
	bridgeID, err := config.EnsureBridgeID()
	if errors.Is(err, config.ErrNotLoggedIn) {
		return config.ErrNotLoggedIn
	}
	if err != nil {
		return fmt.Errorf("bridge id: %w", err)
	}
	cfg.BridgeID = bridgeID

	if bl == nil {
		if bl, err = openBridgeLog(cmd, o.logFile, path); err != nil {
			return err
		}
		defer bl.file.Close()
	}
	if b == nil {
		sock, err := socketPath(path)
		if err != nil {
			return err
		}
		// The listener closes first, because a deferred call runs in reverse order.
		lock, err := lockBridge(path, sock)
		if err != nil {
			return err
		}
		defer lock.Close()
		if control, err = listenControl(sock); err != nil {
			return err
		}
		defer control.Close()
		if b, err = newBridgeUpdate(bl.log, path, lock, control); err != nil {
			return err
		}
		defer b.close()
		b.recovered = leftoverHandover(b.file, bl.log)
	}
	// The state file names the log of the bridge that holds the lock, so a
	// start that the lock refuses leaves it alone.
	ident := newBridgeIdentity(path, bl)
	ident.update(set)
	cursorFile, err := cursorPath(path)
	if err != nil {
		return err
	}
	outboxDir, err := outboxPath(path)
	if err != nil {
		return err
	}

	// A failing account turns off its entries, and never stops the start.
	accounts := checkAccounts(cmd.Context(), set)
	set.SetAccountProblems(accountsOff(accounts))

	r := &router{
		log:          bl.log,
		accountUsage: usage.NewCollector(),
		reach:        bridgeReach,
		worker:       defaultWorkerOps(),
		bridgeID:     bridgeID,
		control:      control,
		source:       newReloadSource(path, defaults, cfg, b.lock),
		update:       b,
		hookRunner:   newHookRunner(hookList, bridgeID, bl.log),
		cursorFile:   cursorFile,
		outboxDir:    outboxDir,
		agent:        cfg.AgentAccount,
		rulesPath:    path,
		logPath:      bl.path,
		lastError:    bl.last,
		onRules:      ident.update,
	}
	pushLogin := checkAgentAccount(cmd.Context(), cfg.AgentAccount, bl.log)
	if control != nil {
		r.credentials = newCredentialBroker(apiClient(cfg), cfg.AgentAccount, control.Addr().String())
	}
	r.identities = newIdentityReporter(cmd.Context(), cfg.AgentAccount, pushLogin, r.credentials, bl.log)
	if dir, err := config.Dir(); err == nil {
		r.assumeAutoUpdate = migrateAutoUpdate(bl.log, dir, path, o.resumeFile != "")
	}
	r.set.Store(set)
	// The cache is read before subscribe, whose adopt dispatches.
	r.baseURL = cfg.BaseURL
	r.usePauseCache(pausePath())
	cleanLaunchScripts(defaultScriptDir(), time.Now(), r.log)
	logBridgeStart(r.log, cmd, set, path, bl.path, bridgeID)
	warnUnknownModes(r.log, set)
	warnMovedHooks(r.log, set)
	warnAgentsOff(r.log, set)
	warnAccountsOff(r.log, accounts)

	return subscribe(cmd, cfg, r)
}

// maxWorkersFlag stays declared, so a service that still passes it starts.
const maxWorkersFlag = "max-workers"

// logBridgeStart warns when --max-workers is set, then logs bridge_started.
func logBridgeStart(log *slog.Logger, cmd *cobra.Command, set *rules.Set, path, logPath, bridgeID string) {
	if cmd.Flags().Changed(maxWorkersFlag) {
		flag, _ := cmd.Flags().GetInt(maxWorkersFlag)
		message := fmt.Sprintf("--max-workers does nothing. The bridge runs at most %d workers, the maxWorkers of rules.yaml. Set maxWorkers in rules.yaml and remove the flag.", set.MaxWorkers())
		if flag != set.MaxWorkers() {
			message = fmt.Sprintf("--max-workers %d does nothing. The bridge runs at most %d workers, the maxWorkers of rules.yaml, not %d. Set maxWorkers in rules.yaml and remove the flag.", flag, set.MaxWorkers(), flag)
		}
		log.Warn("max_workers_flag_ignored", "flag_value", flag, "max_workers", set.MaxWorkers(), "message", message)
	}
	log.Info("bridge_started", "rules", path, "projects", set.Projects(), "work_kinds", set.WorkKinds(),
		"max_workers", set.MaxWorkers(), "worker_pools", poolSizes(set), "log_file", logPath, "bridge_id", bridgeID, "version", cliVersion())
}

// poolSizes names each worker pool and its size, in name order, such as
// default=3,quick=1.
func poolSizes(set *rules.Set) string {
	pools := set.Pools()
	parts := make([]string, 0, len(pools))
	for _, name := range slices.Sorted(maps.Keys(pools)) {
		parts = append(parts, fmt.Sprintf("%s=%d", name, pools[name]))
	}

	return strings.Join(parts, ",")
}

// warnUnknownModes names each permission mode this build does not know. A newer
// claude may accept it, so the bridge starts, and a typo shows in the log.
func warnUnknownModes(log *slog.Logger, set *rules.Set) {
	for _, mode := range set.UnknownPermissionModes() {
		log.Warn("permission_mode_unknown", "mode", mode, "known", rules.PermissionModes)
	}
}

// warnMovedHooks names each hook whose repository moved, and the install that
// moves it to the new name. The hook keeps running under the old one.
func warnMovedHooks(log *slog.Logger, set *rules.Set) {
	for _, e := range set.Hooks() {
		if e.Moved() {
			log.Warn("hook_package_moved", "hook", e.ID(), "fix", "loupe bridge hooks install "+e.CanonicalID()+"@"+e.Ref)
		}
	}
}

// resolveHooks loads the installed hook packages the rule file lists.
func resolveHooks(set *rules.Set) ([]hooks.Hook, error) {
	root, err := config.Dir()
	if err != nil {
		return nil, err
	}

	return hooks.Resolve(root, set.Hooks(), runtime.GOOS)
}

// defaultRulesPath puts the rule file beside config.json.
func defaultRulesPath() (string, error) {
	dir, err := config.Dir()
	if err != nil {
		return "", err
	}

	return filepath.Join(dir, rules.FileName), nil
}

// bridgeLogWriter fans one line out to the log file and to out.
//
// The file comes first. io.MultiWriter stops at the first writer that fails,
// and a reader piped to stdout can leave mid-run, which breaks that pipe. The
// history must survive that.
func bridgeLogWriter(file, out io.Writer) io.Writer {
	return io.MultiWriter(file, out)
}

// newBridgeLogger writes one JSON object per line to w.
//
// slog names the message "msg". The bridge names it "event", because a reader
// selects lines by what happened rather than by a prose message.
func newBridgeLogger(w io.Writer) *slog.Logger {
	return newNamedBridgeLogger(w, nil)
}

// newTrackedLogger is newBridgeLogger with a tracker of the last record at the
// level Warn or above.
func newTrackedLogger(w io.Writer) (*slog.Logger, *lastErrorTracker) {
	return newNamedTrackedLogger(w, nil)
}

// newNamedBridgeLogger is newBridgeLogger with the bridge name on each entry.
func newNamedBridgeLogger(w io.Writer, name *bridgeLogName) *slog.Logger {
	log, _ := newNamedTrackedLogger(w, name)

	return log
}

// newNamedTrackedLogger is newTrackedLogger with the bridge name on each entry.
func newNamedTrackedLogger(w io.Writer, name *bridgeLogName) (*slog.Logger, *lastErrorTracker) {
	last := &lastErrorTracker{}
	var h slog.Handler = slog.NewJSONHandler(w, &slog.HandlerOptions{
		ReplaceAttr: func(_ []string, a slog.Attr) slog.Attr {
			if a.Key == slog.MessageKey {
				a.Key = "event"
			}

			return a
		},
	})
	if name != nil {
		h = namedHandler{Handler: h, name: name}
	}

	return slog.New(&lastErrorHandler{Handler: h, last: last}), last
}

// lastErrorTracker keeps the last record at the level Warn or above.
type lastErrorTracker struct {
	mu   sync.Mutex
	last *statusError
}

func (t *lastErrorTracker) get() *statusError {
	t.mu.Lock()
	defer t.mu.Unlock()
	if t.last == nil {
		return nil
	}
	c := *t.last

	return &c
}

// lastErrorHandler passes each record to the wrapped handler and tells the
// tracker about the ones at Warn or above.
type lastErrorHandler struct {
	slog.Handler
	last *lastErrorTracker
}

func (h *lastErrorHandler) Handle(ctx context.Context, r slog.Record) error {
	if r.Level >= slog.LevelWarn {
		e := statusError{Event: r.Message, Time: r.Time}
		r.Attrs(func(a slog.Attr) bool {
			if a.Key == "message" || (a.Key == "error" && e.Message == "") {
				e.Message = a.Value.String()
			}

			return true
		})
		h.last.mu.Lock()
		h.last.last = &e
		h.last.mu.Unlock()
	}

	return h.Handler.Handle(ctx, r)
}

func (h *lastErrorHandler) WithAttrs(attrs []slog.Attr) slog.Handler {
	return &lastErrorHandler{Handler: h.Handler.WithAttrs(attrs), last: h.last}
}

func (h *lastErrorHandler) WithGroup(name string) slog.Handler {
	return &lastErrorHandler{Handler: h.Handler.WithGroup(name), last: h.last}
}

// defaultLogPath names the log the bridge appends to. The temp directory is the
// fallback, because a host with no config directory must still keep a history.
func defaultLogPath() string {
	d, err := config.Dir()
	if err != nil {
		return filepath.Join(os.TempDir(), "loupe", "bridge.log")
	}

	return filepath.Join(d, "bridge.log")
}

// subscribe blocks in the foreground until Ctrl-C or SIGTERM. Cancelling also
// kills every worker in flight, so the bridge leaves no unattended claude
// behind. It then waits for their reports before it returns.
//
// The shutdown drops the queue before it waits. Subscribe calls the handler on
// this goroutine, so no event can arrive after it returns.
//
// The server publishes every event of a project on its owner's topic too, so
// one topic follows every project, including one created after the start. The
// router ignores a project the file does not map.
func subscribe(cmd *cobra.Command, cfg config.Config, r *router) error {
	// stop runs last, after every worker has ended, on an early return too.
	r.hookRunner.start()
	defer r.hookRunner.stop()

	ctx, stop := signal.NotifyContext(cmd.Context(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	r.ctx = ctx
	go r.priceLoop(ctx)
	go r.itemSessions().run(ctx, projectDirs(r.rules()), itemSweepInterval)
	startLoginGate(ctx, r.log, cfg)
	defer setBridgeGate(nil)

	// The queue closes after the workers, so it sees every report a dying worker
	// still makes, and its grace window can send them.
	queue := outbound.New(ctx, r.log)
	r.reports = queue
	r.runs = newRunReports(apiClient(cfg), r.log)
	if r.outboxDir != "" {
		r.runs.outbox = &outbox{dir: r.outboxDir, baseURL: cfg.BaseURL, bridgeID: r.bridgeID}
	}
	defer queue.Close()

	set := r.rules()
	if r.bridgeID != "" {
		announce(ctx, apiClient(cfg), r.bridgeID, set, r.identities, r.log)
	}
	events, err := startEvents(ctx, cfg, r.bridgeID, set)
	if err != nil {
		logUpgradeRequired(r.log, err)

		return err
	}
	r.projects, r.topic = set.Projects(), events.Topic
	if r.readCard == nil {
		r.readCard = apiClient(cfg).ReadCard
	}
	if r.resolvePin == nil {
		r.resolvePin = apiClient(cfg).ResolveExperimentPin
	}
	if r.ackCommand == nil {
		r.ackCommand = apiClient(cfg).AckCommand
	}
	if r.reportRunUsage == nil {
		r.reportRunUsage = func(ctx context.Context, handle, sessionID, runID string, usage api.Usage) error {
			_, err := apiClient(cfg).ReportRunUsage(ctx, handle, sessionID, runID, usage)

			return err
		}
	}
	if r.workAPI == nil {
		r.workAPI = apiClient(cfg)
	}
	if r.replay == nil {
		r.replay = func(ctx context.Context, after int64) (api.Replay, error) {
			return apiClient(cfg).Replay(ctx, r.bridgeID, after)
		}
	}
	if r.readHolds == nil {
		r.readHolds = apiClient(cfg).CardHolds
	}
	r.applyFlags(events)
	r.restoreState(events.Head)
	var updates *updater
	var watched <-chan struct{}
	if r.bridgeID != "" {
		hb := newHeartbeater(ctx, queue, apiClient(cfg), r.bridgeID, heartbeatBody(set), heartbeatInterval(events), r.log)
		if dir, err := config.Dir(); err != nil {
			r.log.Warn("update_skipped", "reason", err.Error())
		} else if setup := config.Current(); setup.Source != config.SourceDefault {
			// A setup other than the default never replaces a binary. The
			// default setup updates the shared one, and a build is rebuilt by
			// its recipe.
			state := updateOff
			if setupbuild.Built(setup.Dir) {
				state = updateDev
			}
			hb.update = func() api.HeartbeatUpdate { return api.HeartbeatUpdate{State: state} }
		} else {
			hook := logStaged(r.log, version)
			if r.update != nil {
				hook = r.update.handover(r, version)
			}
			updates = newUpdater(r.log, version, dir, func() bool { return autoUpdateOn(r.rules(), r.assumeAutoUpdate) }, hook)
			r.updateCheck = func() { updates.nudge(time.Now()) }
			if r.update != nil {
				updates.executable = r.update.installed
				if r.update.crashedFrom != "" {
					updates.markRolledBack(r.update.crashedFrom)
				}
			}
			updates.install = installMethod(updates.executable)
			hb.onRange, hb.update = updates.setRange, updates.state
		}
		if r.update != nil {
			hb.onSent = r.update.markBeat
		}
		hb.onReply, hb.onLost = r.onHeartbeatReply, r.loseClaims
		hb.worktrees = func(ctx context.Context) []api.WorktreeReport { return worktreeReports(ctx, r.rules()) }
		hb.repositories = func(ctx context.Context) []api.RepositoryReport { return repositoryReports(ctx, r.rules()) }
		hb.gitIdentities = func(context.Context) ([]api.GitIdentityReport, time.Duration) {
			return r.identities.rows(identityTargets(r.rules()))
		}
		hb.sessions = newSessionLiveness(r.rules).readings
		// Adopted runs can end on their own goroutines, and read heartbeat
		// under mu.
		r.mu.Lock()
		hb.paused = r.personPaused
		r.heartbeat = hb
		hb.usage, hb.skips = r.accountUsage, &r.skips
		r.notePoolsLocked()
		r.noteClaimsLocked()
		r.mu.Unlock()
		r.hookRunner.attach(r.heartbeat)
		r.heartbeat.start()
		r.syncHostSampler()
	}
	if r.update != nil && r.update.resumed != nil {
		watched = r.update.watchHealth(ctx, r, updates)
	} else if updates != nil {
		updates.start(ctx)
	}
	// The control socket starts last, so no reload races the writes above.
	var served <-chan struct{}
	if r.control != nil {
		served = serveControl(ctx, r.control, controlOps{
			stop:   stop,
			reload: func(ctx context.Context) reloadResult { return r.reload(ctx, r.source) },
			credential: func(ctx context.Context, runID string) credentialResult {
				if r.credentials == nil {
					return credentialResult{Problem: "this bridge hands out no credentials"}
				}

				return r.credentials.answer(ctx, runID)
			},
			env:    r.runEnv,
			status: r.status,
			update: func(ctx context.Context, reply func(updateResult)) updateResult {
				if updates == nil {
					problem := "this bridge runs no update checks"
					if setup := config.Current(); setup.Source != config.SourceDefault {
						problem = "this bridge belongs to the setup " + setup.Dir + ", which runs no update checks"
					}

					return updateResult{From: version, Outcome: outcomeFailed, Problem: problem}
				}

				return updates.checkNow(ctx, func(to string) {
					reply(updateResult{OK: true, From: version, To: to, Outcome: outcomeHandingOver})
				})
			},
		})
		r.log.Info("control_listening", "socket", r.control.Addr().String())
	}

	err = transport.Subscribe(ctx, &http.Client{Transport: withUserAgent(nil)}, events.HubURL, []string{events.Topic}, jwtRefresher(cfg, r.bridgeID, events.JWT, r.onRefresh), r.handler())
	failed := err != nil && ctx.Err() == nil
	if failed {
		logUpgradeRequired(r.log, err)
	}
	r.shutdown()
	r.wg.Wait()
	// The reports stay on the server, so a pending one is dropped rather than
	// sent. Its goroutines return once the context ends.
	stop()
	if r.heartbeat != nil {
		r.heartbeat.wait()
	}
	if watched != nil {
		<-watched
	}
	if updates != nil {
		updates.stop()
	}
	if served != nil {
		<-served
	}
	if failed {
		return err
	}

	return nil
}

// logStaged is the hook of a staged release in a bridge that cannot hand over.
func logStaged(log *slog.Logger, from string) stagedHook {
	return func(_ context.Context, c update.Candidate, path string) stagedOutcome {
		log.Info("update_staged", "from", from, "to", c.Version.String(), "path", path)

		return stagedDeferred
	}
}

// announce sends one heartbeat before the first GET /api/events, because the
// server serves the events only to a bridge whose heartbeat names the
// work-requests capability. A failure leaves GET /api/events to answer.
func announce(ctx context.Context, client heartbeatSender, bridgeID string, set *rules.Set, identities *identityReporter, log *slog.Logger) {
	body := heartbeatBody(set)
	body.GitIdentities = identities.start(ctx, set)
	body.Capabilities = slices.Concat(bridgeCapabilities, body.Capabilities)
	if _, err := client.Heartbeat(ctx, bridgeID, body); err != nil {
		log.Warn("start_heartbeat_failed", "error", err.Error())
	}
}

// logUpgradeRequired names the refusal of a server that serves no bridge
// without work requests, because the bridge then stops rather than retry.
func logUpgradeRequired(log *slog.Logger, err error) {
	if errors.Is(err, api.ErrUpgradeRequired) {
		log.Error("bridge_upgrade_required", "error", err.Error(),
			"message", "The server serves events only to a bridge that runs work requests. Upgrade the loupe CLI and map the work kinds under work: in rules.yaml. The bridge stops.")
	}
}

// startEvents reads GET /api/events, and fails when it does not list a mapped
// project.
func startEvents(ctx context.Context, cfg config.Config, bridgeID string, set *rules.Set) (api.Events, error) {
	events, err := apiClient(cfg).Events(ctx, bridgeID)
	if err != nil {
		return events, err
	}
	if missing := missingProjects(set, events); len(missing) > 0 {
		return events, fmt.Errorf("GET /api/events does not list %s, so no event of theirs can reach the bridge", strings.Join(missing, ", "))
	}

	return events, nil
}

// heartbeatBody names the projects the rule file maps, by id, the CLI
// version, the capabilities and the kinds of the work map, the bridge name, and
// the state of each account. The git identities join it when it goes out.
func heartbeatBody(set *rules.Set) api.Heartbeat {
	ids := []string{}
	for _, slug := range set.Projects() {
		ids = append(ids, set.ProjectID(slug))
	}

	name := set.Name()

	caps := set.Capabilities()
	if set.AcceptEntries() {
		caps = append(caps, rules.CapabilityAddEntry)
	}

	return api.Heartbeat{Projects: ids, CLIVersion: cliVersion(), Capabilities: caps, WorkKinds: set.WorkKindsOn(), Name: &name, Accounts: accountReports(set), CommandEntries: commandEntryReports(set)}
}

// missingProjects names the mapped projects that GET /api/events does not list:
// deleted, or no longer the user's. Their rules can never fire.
func missingProjects(set *rules.Set, events api.Events) []string {
	listed := map[string]bool{}
	for _, p := range events.Projects {
		listed[strings.ToLower(p.ID)] = true
	}
	var missing []string
	for _, slug := range set.Projects() {
		if !listed[set.ProjectID(slug)] {
			missing = append(missing, slug)
		}
	}

	return missing
}

// jwtRefresher hands out first for the first connection, then mints a fresh
// subscriber JWT per attempt and gives each fresh answer to onRefresh.
// Subscriber JWTs are short-lived, so a bridge left running would otherwise
// reconnect with an expired token forever once the first one lapsed. Subscribe
// calls it from one goroutine. A refusal to upgrade ends the subscription.
func jwtRefresher(cfg config.Config, bridgeID, first string, onRefresh func(api.Events)) transport.TokenFunc {
	return func(ctx context.Context) (string, error) {
		if first != "" {
			jwt := first
			first = ""

			return jwt, nil
		}
		events, err := apiClient(cfg).Events(ctx, bridgeID)
		if errors.Is(err, api.ErrUpgradeRequired) {
			return "", transport.Fatal(err)
		}
		if err != nil {
			return "", err
		}
		onRefresh(events)

		return events.JWT, nil
	}
}

// status describes the bridge for the status op. It reads state and makes no
// request, so it answers while the login is expired.
func (r *router) status() statusResult {
	res := statusResult{
		Name:      r.rules().Name(),
		RulesPath: r.rulesPath,
		PID:       os.Getpid(),
		Version:   cliVersion(),
		LogFile:   r.logPath,
		Login:     loginValid,
		Workers:   []statusWorker{},
	}
	if g := currentBridgeGate(); g != nil && g.expired() {
		res.Login = loginExpired
	}
	if r.lastError != nil {
		res.LastError = r.lastError.get()
	}
	r.mu.Lock()
	if r.heartbeat != nil {
		res.LastHeartbeatAt = r.heartbeat.lastOK.Load()
	}
	for _, run := range r.live {
		w := statusWorker{Card: run.p.work.CardNumber, Kind: run.p.work.Kind, StartedAt: run.began}
		if w.Card == 0 {
			w.Card = run.p.event.CardNumber
		}
		if w.Kind == "" {
			w.Kind = run.p.rule
		}
		res.Workers = append(res.Workers, w)
	}
	r.mu.Unlock()
	slices.SortFunc(res.Workers, func(a, b statusWorker) int { return a.StartedAt.Compare(b.StartedAt) })

	return res
}
