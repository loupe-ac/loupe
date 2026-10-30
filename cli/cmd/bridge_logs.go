package cmd

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/logfile"
	"github.com/spf13/cobra"
)

// logPoll is how often -f looks for new lines and for a rotation.
const logPoll = 250 * time.Millisecond

// bridgeLogsOptions are the flags of `loupe bridge logs`.
type bridgeLogsOptions struct {
	logFile, bridge string
	lines           int
	follow, asJSON  bool
}

func newBridgeLogsCmd() *cobra.Command {
	var o bridgeLogsOptions

	cmd := &cobra.Command{
		Use:   "logs",
		Short: "Read the log of your bridges",
		Long: "Prints the last entries of the bridge log, with the entries of every bridge on this machine " +
			"merged by time. The command finds the logs of running and of stopped bridges, because each " +
			"bridge leaves a state file that names its log. It reads the dated files of a rotated log too. " +
			"It needs no network and no running bridge.\n\n" +
			"The output is logfmt: the time, the level and the event, then the other keys of the entry. " +
			"--json prints each entry as the bridge wrote it. --bridge keeps the entries of one bridge name; " +
			"an entry written before the bridge named itself shows only without --bridge. A name that two " +
			"running bridges share is refused. " +
			"-f keeps reading, and follows the log across a daily rotation.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			return runBridgeLogs(cmd, o)
		},
	}
	cmd.Flags().StringVar(&o.logFile, "log-file", "", "read the bridge log at this `path`; empty reads the logs of every bridge on this machine")
	cmd.Flags().StringVar(&o.bridge, "bridge", "", "keep the entries of the bridge with this `name`")
	cmd.Flags().IntVarP(&o.lines, "lines", "n", 50, "print the last `count` entries")
	cmd.Flags().BoolVarP(&o.follow, "follow", "f", false, "keep reading and print new entries until interrupted")
	cmd.Flags().BoolVar(&o.asJSON, "json", false, "print each entry as the bridge wrote it")

	return cmd
}

// logEntry is one line of a log with the time it carries.
type logEntry struct {
	at   time.Time
	line []byte
	// bridge is the name on the entry, empty when it has none.
	bridge string
}

func parseLogEntry(line []byte) logEntry {
	line = bytes.TrimRight(line, "\r\n")
	e := logEntry{line: line}
	var head struct {
		Time   time.Time `json:"time"`
		Bridge string    `json:"bridge"`
	}
	if json.Unmarshal(line, &head) == nil {
		e.at, e.bridge = head.Time, head.Bridge
	}

	return e
}

// logPaths names the live file of each log to read, and each path it checked.
// A path counts when a file of its log exists. -f also waits for a --log-file
// path that has no file yet.
func logPaths(o bridgeLogsOptions, states []bridgeState) (paths, checked []string) {
	if o.logFile != "" {
		checked = []string{o.logFile}
	} else {
		for _, st := range states {
			if st.LogFile != "" && !slices.Contains(checked, st.LogFile) {
				checked = append(checked, st.LogFile)
			}
		}
		// A bridge on an older CLI leaves no state file, and logs to the default path.
		if def := defaultLogPath(); !slices.Contains(checked, def) {
			checked = append(checked, def)
		}
	}
	for _, p := range checked {
		if len(logfile.Family(p)) > 0 || (o.follow && p == o.logFile) {
			paths = append(paths, p)
		}
	}

	return paths, checked
}

// checkSharedName refuses a name that two running bridges share, because
// their entries cannot be told apart.
func checkSharedName(states []bridgeState, name string) error {
	running, err := runningStates(states)
	if err != nil {
		return err
	}
	same := 0
	for _, st := range running {
		if st.Name == name {
			same++
		}
	}
	if same > 1 {
		return fmt.Errorf("Two running bridges are named %q. Set `name:` in one rule file to tell them apart.", name)
	}

	return nil
}

func runBridgeLogs(cmd *cobra.Command, o bridgeLogsOptions) error {
	if o.lines < 0 {
		return errors.New("-n must not be negative")
	}
	var states []bridgeState
	if o.logFile == "" || o.bridge != "" {
		var err error
		if states, err = readBridgeStates(); err != nil {
			return err
		}
	}
	if o.bridge != "" {
		if err := checkSharedName(states, o.bridge); err != nil {
			return err
		}
	}
	paths, checked := logPaths(o, states)
	if len(paths) == 0 {
		return fmt.Errorf("no bridge log found; checked %s. Start a bridge, or pass --log-file", strings.Join(checked, ", "))
	}
	out := cmd.OutOrStdout()

	var follows []*logFollower
	var entries []logEntry
	for _, path := range paths {
		var f *logFollower
		if o.follow {
			// Open the live file first, so no entry falls between the tail and the follow.
			f = openFollower(path)
			follows = append(follows, f)
		}
		got, err := tailFamily(path, o.lines, o.bridge, f)
		if err != nil {
			return err
		}
		entries = append(entries, got...)
	}
	slices.SortStableFunc(entries, func(a, b logEntry) int { return a.at.Compare(b.at) })
	if len(entries) > o.lines {
		entries = entries[len(entries)-o.lines:]
	}
	for _, e := range entries {
		printLogEntry(out, e, o.asJSON)
	}
	if !o.follow {
		return nil
	}

	for {
		select {
		case <-cmd.Context().Done():
			return nil
		case <-time.After(logPoll):
		}
		var fresh []logEntry
		for _, f := range follows {
			fresh = append(fresh, f.poll()...)
		}
		fresh = filterBridge(fresh, o.bridge)
		slices.SortStableFunc(fresh, func(a, b logEntry) int { return a.at.Compare(b.at) })
		for _, e := range fresh {
			printLogEntry(out, e, o.asJSON)
		}
	}
}

func filterBridge(entries []logEntry, bridge string) []logEntry {
	if bridge == "" {
		return entries
	}

	return slices.DeleteFunc(entries, func(e logEntry) bool { return e.bridge != bridge })
}

// tailFamily reads the last n entries of the log at path that name bridge, or
// of every bridge when bridge is empty. It reads the newest file first, and
// goes on into the dated files until it has n. With follow set, it reads the
// file that the follower holds only up to where the follower starts, and
// leaves a newer live file to the follower.
func tailFamily(path string, n int, bridge string, follow *logFollower) ([]logEntry, error) {
	var keep func([]byte) bool
	if bridge != "" {
		keep = func(line []byte) bool { return parseLogEntry(line).bridge == bridge }
	}
	var followed os.FileInfo
	if follow != nil && follow.f != nil {
		followed, _ = follow.f.Stat()
	}
	files := logfile.Family(path)
	var entries []logEntry
	for i := len(files) - 1; i >= 0 && len(entries) < n; i-- {
		f, err := os.Open(files[i])
		if err != nil {
			if errors.Is(err, os.ErrNotExist) {
				continue
			}

			return nil, fmt.Errorf("read %s: %w", files[i], err)
		}
		info, err := f.Stat()
		if err != nil {
			f.Close()

			return nil, err
		}
		size := info.Size()
		if follow != nil {
			if followed != nil && os.SameFile(info, followed) {
				size = min(size, follow.offset)
			} else if files[i] == path {
				f.Close()

				continue
			}
		}
		lines, err := lastLines(f, size, n-len(entries), keep)
		f.Close()
		if err != nil {
			return nil, fmt.Errorf("read %s: %w", files[i], err)
		}
		got := make([]logEntry, len(lines))
		for j, l := range lines {
			got[j] = parseLogEntry(l)
		}
		entries = append(got, entries...)
	}

	return entries, nil
}

// lastLines returns the last n non-empty lines among the first size bytes of
// r that keep accepts, oldest first. A nil keep accepts every line. It reads
// backward in blocks, so a large file costs only the blocks that hold the lines.
func lastLines(r io.ReaderAt, size int64, n int, keep func([]byte) bool) ([][]byte, error) {
	const block = 64 * 1024
	var lines [][]byte
	// cut holds the start of a line that the edge of the last block cut.
	var cut []byte
	end := size
	for end > 0 && len(lines) < n {
		start := max(end-block, 0)
		buf := make([]byte, end-start)
		if _, err := r.ReadAt(buf, start); err != nil && !errors.Is(err, io.EOF) {
			return nil, err
		}
		data := append(buf, cut...)
		end = start
		cut = nil
		if end > 0 {
			i := bytes.IndexByte(data, '\n')
			if i < 0 {
				cut = data

				continue
			}
			cut, data = data[:i], data[i+1:]
		}
		split := bytes.Split(data, []byte("\n"))
		for j := len(split) - 1; j >= 0 && len(lines) < n; j-- {
			if l := split[j]; len(bytes.TrimSpace(l)) > 0 && (keep == nil || keep(l)) {
				lines = append(lines, l)
			}
		}
	}
	slices.Reverse(lines)

	return lines, nil
}

// logFollower reads the new entries of the live file of one log. When the file
// at the path is another file, the log rotated: it reads the old file to its
// end, then the new file from its start.
type logFollower struct {
	path   string
	f      *os.File
	offset int64
	rest   []byte
}

func openFollower(path string) *logFollower {
	f := &logFollower{path: path}
	if file, err := os.Open(path); err == nil {
		f.f = file
		f.offset, _ = file.Seek(0, io.SeekEnd)
	}

	return f
}

func (l *logFollower) poll() []logEntry {
	var out []logEntry
	for {
		if l.f == nil {
			file, err := os.Open(l.path)
			if err != nil {
				return out
			}
			l.f, l.offset = file, 0
		}
		out = append(out, l.drain()...)
		if !l.rotated() {
			return out
		}
		l.f.Close()
		l.f, l.rest = nil, nil
	}
}

// drain reads to the end of the open file and returns the complete lines.
func (l *logFollower) drain() []logEntry {
	data, _ := io.ReadAll(io.NewSectionReader(l.f, l.offset, 1<<62))
	l.offset += int64(len(data))
	data = append(l.rest, data...)
	var out []logEntry
	for {
		i := bytes.IndexByte(data, '\n')
		if i < 0 {
			break
		}
		if line := data[:i]; len(bytes.TrimSpace(line)) > 0 {
			out = append(out, parseLogEntry(bytes.Clone(line)))
		}
		data = data[i+1:]
	}
	l.rest = bytes.Clone(data)

	return out
}

// rotated reports whether the path now names another file than the open one.
func (l *logFollower) rotated() bool {
	open, err := l.f.Stat()
	if err != nil {
		return false
	}
	now, err := os.Stat(l.path)
	if err != nil {
		// The file moved and nothing took its place yet. Wait for the next write.
		return false
	}

	return !os.SameFile(open, now)
}

func printLogEntry(w io.Writer, e logEntry, asJSON bool) {
	if asJSON {
		fmt.Fprintf(w, "%s\n", e.line)

		return
	}
	fmt.Fprintf(w, "%s\n", logfmt(e.line))
}

// logfmt writes the time, the level and the event first, then the other keys
// in file order. A line that is not a JSON object stays as it is.
func logfmt(line []byte) string {
	dec := json.NewDecoder(bytes.NewReader(line))
	if tok, err := dec.Token(); err != nil || tok != json.Delim('{') {
		return string(line)
	}
	type pair struct{ key, val string }
	var pairs []pair
	for dec.More() {
		keyTok, err := dec.Token()
		key, ok := keyTok.(string)
		if err != nil || !ok {
			return string(line)
		}
		var raw json.RawMessage
		if dec.Decode(&raw) != nil {
			return string(line)
		}
		pairs = append(pairs, pair{key, logfmtValue(raw)})
	}
	var b strings.Builder
	first := func(key string) {
		for i, p := range pairs {
			if p.key == key {
				writePair(&b, p.key, p.val)
				pairs = slices.Delete(pairs, i, i+1)

				return
			}
		}
	}
	first("time")
	first("level")
	first("event")
	for _, p := range pairs {
		writePair(&b, p.key, p.val)
	}

	return b.String()
}

func writePair(b *strings.Builder, key, val string) {
	if b.Len() > 0 {
		b.WriteByte(' ')
	}
	b.WriteString(key)
	b.WriteByte('=')
	b.WriteString(val)
}

// logfmtValue quotes a string that holds a space, a quote, an equals sign or
// nothing, and keeps a number or a boolean as it is. An object or an array
// shows as quoted JSON.
func logfmtValue(raw json.RawMessage) string {
	var s string
	if json.Unmarshal(raw, &s) == nil {
		if s == "" || strings.ContainsAny(s, " \t\"=\\") || strings.ContainsFunc(s, func(r rune) bool { return r < 0x20 }) {
			return strconv.Quote(s)
		}

		return s
	}
	switch raw[0] {
	case '{', '[':
		var compact bytes.Buffer
		if json.Compact(&compact, raw) == nil {
			return strconv.Quote(compact.String())
		}
	}

	return string(raw)
}
