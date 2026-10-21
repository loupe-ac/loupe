package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/update"
)

// keptReportAge is how long a kept report waits for a connect. An older one is
// deleted, so a file made for an instance that is gone does not stay forever.
const keptReportAge = 7 * 24 * time.Hour

// keptReportFormat versions the outbox file.
const keptReportFormat = 1

// keptReport is one outbox file: the final report of a worker run that the
// queue could not deliver, with what a later connect needs to send it.
type keptReport struct {
	Format   int                `json:"format"`
	BaseURL  string             `json:"baseUrl"`
	BridgeID string             `json:"bridgeId"`
	Handle   string             `json:"handle"`
	RunID    string             `json:"runId"`
	Rule     string             `json:"rule"`
	KeptAt   time.Time          `json:"keptAt"`
	Report   api.RunStateReport `json:"report"`
}

// keptEntry is a kept report and the file that holds it.
type keptEntry struct {
	path string
	keptReport
}

// outbox keeps the final reports of worker runs on disk. One directory belongs
// to one rule file, so two bridges never send each other's reports.
type outbox struct {
	dir      string
	baseURL  string
	bridgeID string
	now      func() time.Time
}

// outboxPath names the outbox directory of the bridge that reads rulesPath. It
// shares the key of the cursor file.
func outboxPath(rulesPath string) (string, error) {
	key, err := lockKey(rulesPath)
	if err != nil {
		return "", err
	}

	return bridgeFile("outbox-", key, "")
}

var unsafeName = regexp.MustCompile(`[^A-Za-z0-9_-]`)

// keep writes one final report. A second report of the same run state replaces
// the first.
func (o *outbox) keep(handle, runID string, report api.RunStateReport) error {
	now := time.Now
	if o.now != nil {
		now = o.now
	}
	data, err := json.Marshal(keptReport{
		Format:   keptReportFormat,
		BaseURL:  o.baseURL,
		BridgeID: o.bridgeID,
		Handle:   handle,
		RunID:    runID,
		Rule:     report.Rule,
		KeptAt:   now(),
		Report:   report,
	})
	if err != nil {
		return fmt.Errorf("encode kept report: %w", err)
	}
	name := unsafeName.ReplaceAllString(runID+"-"+report.State, "_") + ".json"

	return update.WriteFile(filepath.Join(o.dir, name), data, 0o600)
}

// list reads every kept report, oldest first. A file it cannot read is named in
// bad, and stays on disk.
func (o *outbox) list() (kept []keptEntry, bad []string, err error) {
	files, err := os.ReadDir(o.dir)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, nil, nil
	}
	if err != nil {
		return nil, nil, fmt.Errorf("read outbox: %w", err)
	}
	for _, f := range files {
		if f.IsDir() || !strings.HasSuffix(f.Name(), ".json") {
			continue
		}
		path := filepath.Join(o.dir, f.Name())
		b, err := os.ReadFile(path)
		var k keptReport
		if err == nil {
			err = json.Unmarshal(b, &k)
		}
		if err != nil || k.Format != keptReportFormat {
			bad = append(bad, path)

			continue
		}
		k.Report.Rule = k.Rule
		kept = append(kept, keptEntry{path: path, keptReport: k})
	}
	slices.SortFunc(kept, func(a, b keptEntry) int { return a.KeptAt.Compare(b.KeptAt) })

	return kept, bad, nil
}

// remove deletes a kept report. A file already gone is no error.
func (o *outbox) remove(path string) {
	_ = os.Remove(path)
}
