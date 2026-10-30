package cmd

import (
	"cmp"
	"context"
	"encoding/json"
	"log/slog"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync/atomic"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// bridgeLogName holds the name that each log entry of a bridge carries. The
// bridge sets it at the start and at each reload.
type bridgeLogName struct {
	v atomic.Value
}

func (n *bridgeLogName) set(name string) {
	n.v.Store(name)
}

func (n *bridgeLogName) get() string {
	name, _ := n.v.Load().(string)

	return name
}

// namedHandler adds the bridge attribute to each record. It comes first among
// the attributes of the record, so it reads next to the event.
type namedHandler struct {
	slog.Handler
	name *bridgeLogName
}

func (h namedHandler) Handle(ctx context.Context, r slog.Record) error {
	name := h.name.get()
	if name == "" {
		return h.Handler.Handle(ctx, r)
	}
	named := slog.NewRecord(r.Time, r.Level, r.Message, r.PC)
	named.AddAttrs(slog.String("bridge", name))
	r.Attrs(func(a slog.Attr) bool {
		named.AddAttrs(a)

		return true
	})

	return h.Handler.Handle(ctx, named)
}

func (h namedHandler) WithAttrs(attrs []slog.Attr) slog.Handler {
	return namedHandler{Handler: h.Handler.WithAttrs(attrs), name: h.name}
}

func (h namedHandler) WithGroup(name string) slog.Handler {
	return namedHandler{Handler: h.Handler.WithGroup(name), name: h.name}
}

// bridgeState is the file that a bridge leaves beside its lock file. It
// outlives the bridge, so `loupe bridge logs` finds the log of a stopped one.
type bridgeState struct {
	Name      string    `json:"name"`
	RulesPath string    `json:"rulesPath"`
	LogFile   string    `json:"logFile"`
	PID       int       `json:"pid"`
	Version   string    `json:"version"`
	StartedAt time.Time `json:"startedAt"`
	// file is the path of the state file itself.
	file string
}

// statePath names the state file of the bridge that reads rulesPath. It shares
// the hash of lockPath.
func statePath(rulesPath string) (string, error) {
	key, err := lockKey(rulesPath)
	if err != nil {
		return "", err
	}

	return bridgeFile("bridge-", key, ".state.json")
}

// writeBridgeState replaces the state file with a rename, so a reader never
// sees half a file.
func writeBridgeState(path string, st bridgeState) error {
	data, err := json.Marshal(st)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".state-*.tmp")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(append(data, '\n')); err != nil {
		tmp.Close()

		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}

	return os.Rename(tmp.Name(), path)
}

// readBridgeStates reads the state file of every bridge of this setup, and
// skips a file that does not parse.
func readBridgeStates() ([]bridgeState, error) {
	dir, err := config.Dir()
	if err != nil {
		return nil, err
	}
	paths, err := filepath.Glob(filepath.Join(dir, "bridge-*.state.json"))
	if err != nil {
		return nil, err
	}
	var states []bridgeState
	for _, p := range paths {
		data, err := os.ReadFile(p)
		if err != nil {
			continue
		}
		var st bridgeState
		if json.Unmarshal(data, &st) == nil {
			st.file = p
			states = append(states, st)
		}
	}

	return states, nil
}

// bridgeIdentity names the bridge on its log and in its state file.
type bridgeIdentity struct {
	rulesPath string
	log       *bridgeLog
	started   time.Time
}

func newBridgeIdentity(rulesPath string, log *bridgeLog) *bridgeIdentity {
	return &bridgeIdentity{rulesPath: rulesPath, log: log, started: time.Now()}
}

// update takes the name of set, and rewrites the state file. A failure to
// write it is logged and never stops the bridge.
func (i *bridgeIdentity) update(set *rules.Set) {
	name := logName(set.Name())
	i.log.name.set(name)
	logPath, _ := filepath.Abs(i.log.path)
	path, err := statePath(i.rulesPath)
	if err == nil {
		err = writeBridgeState(path, bridgeState{Name: name, RulesPath: i.rulesPath, LogFile: logPath, PID: os.Getpid(), Version: cliVersion(), StartedAt: i.started})
	}
	if err != nil {
		i.log.log.Warn("bridge_state_failed", "error", err.Error())
	}
}

// logName is the name a bridge carries on its log: the name of its rule file,
// or the host name when the file opts out.
func logName(setName string) string {
	host, _ := os.Hostname()

	return cmp.Or(setName, host)
}

// runningStates keeps the states whose bridge holds its lock now. A state file
// and the lock file of one bridge share their hash.
func runningStates(states []bridgeState) ([]bridgeState, error) {
	held, err := heldBridges()
	if err != nil {
		return nil, err
	}
	var running []bridgeState
	for _, st := range states {
		lock := strings.TrimSuffix(st.file, ".state.json") + ".lock"
		if slices.ContainsFunc(held, func(b heldBridge) bool { return slices.Contains(b.locks, lock) }) {
			running = append(running, st)
		}
	}

	return running, nil
}
