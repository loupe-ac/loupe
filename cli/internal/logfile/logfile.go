// Package logfile writes a log file that rotates once a day.
//
// Several bridges can append to one file, so each write takes a lock, opens
// the file, rotates it when its last write was on an earlier day, and closes
// it. No writer keeps the file open, so none writes into a renamed file.
package logfile

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"syscall"
	"time"
)

// Keep is the number of dated files kept next to the live file.
const Keep = 13

const dayLayout = "2006-01-02"

// Writer appends to a log file and rotates it on the first write of a day.
type Writer struct {
	path string
	lock *os.File
	mu   sync.Mutex
	// now is the clock; tests replace it.
	now func() time.Time
}

// Open prepares the writer and creates the directory of path. The file itself
// is created by the first write.
func Open(path string) (*Writer, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, fmt.Errorf("create log directory: %w", err)
	}
	lock, err := os.OpenFile(path+".lock", os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return nil, fmt.Errorf("open log lock: %w", err)
	}
	w := &Writer{path: path, lock: lock, now: time.Now}
	if err := w.write(nil); err != nil {
		lock.Close()

		return nil, err
	}

	return w, nil
}

// Path is the path of the live file.
func (w *Writer) Path() string {
	return w.path
}

// Write appends p as one entry.
func (w *Writer) Write(p []byte) (int, error) {
	if err := w.write(p); err != nil {
		return 0, err
	}

	return len(p), nil
}

// Close releases the lock file.
func (w *Writer) Close() error {
	return w.lock.Close()
}

func (w *Writer) write(p []byte) error {
	w.mu.Lock()
	defer w.mu.Unlock()
	if err := flock(w.lock, syscall.LOCK_EX); err != nil {
		return fmt.Errorf("lock log: %w", err)
	}
	defer flock(w.lock, syscall.LOCK_UN)

	today := day(w.now())
	if info, err := os.Stat(w.path); err == nil {
		if last := day(info.ModTime()); last.Before(today) {
			if err := rotate(w.path, last); err != nil {
				return err
			}
			prune(w.path, today)
		}
	}
	f, err := os.OpenFile(w.path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return fmt.Errorf("open log file: %w", err)
	}
	defer f.Close()
	if len(p) == 0 {
		return nil
	}
	_, err = f.Write(p)

	return err
}

func flock(f *os.File, how int) error {
	for {
		err := syscall.Flock(int(f.Fd()), how)
		if err != syscall.EINTR {
			return err
		}
	}
}

// day cuts t down to midnight in its own zone.
func day(t time.Time) time.Time {
	y, m, d := t.Date()

	return time.Date(y, m, d, 0, 0, 0, 0, t.Location())
}

// DatedPath is the name that the live file at path takes for day, such as
// bridge.2026-10-29.log for bridge.log. A path with no extension gets the date
// as a suffix.
func DatedPath(path string, day time.Time) string {
	ext := filepath.Ext(path)

	return strings.TrimSuffix(path, ext) + "." + day.Format(dayLayout) + ext
}

// rotate renames the live file to the name of the day of its last write. When
// that name exists already, the live file joins it.
func rotate(path string, last time.Time) error {
	dated := DatedPath(path, last)
	if _, err := os.Lstat(dated); os.IsNotExist(err) {
		if err := os.Rename(path, dated); err != nil {
			return fmt.Errorf("rotate log: %w", err)
		}

		return nil
	}
	src, err := os.Open(path)
	if err != nil {
		return fmt.Errorf("rotate log: %w", err)
	}
	defer src.Close()
	dst, err := os.OpenFile(dated, os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return fmt.Errorf("rotate log: %w", err)
	}
	defer dst.Close()
	if _, err := io.Copy(dst, src); err != nil {
		return fmt.Errorf("rotate log: %w", err)
	}

	return os.Remove(path)
}

// prune deletes each dated file older than the Keep days before today. A
// failure leaves the file for the next rotation.
func prune(path string, today time.Time) {
	cutoff := today.AddDate(0, 0, -Keep)
	for _, f := range dated(path) {
		if f.day.Before(cutoff) {
			os.Remove(f.path)
		}
	}
}

type datedFile struct {
	path string
	day  time.Time
}

// dated lists the dated files of the log at path, oldest first.
func dated(path string) []datedFile {
	ext := filepath.Ext(path)
	stem := strings.TrimSuffix(path, ext)
	matches, _ := filepath.Glob(stem + ".????-??-??" + ext)
	var files []datedFile
	for _, m := range matches {
		name := strings.TrimSuffix(strings.TrimPrefix(m, stem+"."), ext)
		d, err := time.ParseInLocation(dayLayout, name, time.Local)
		if err != nil {
			continue
		}
		files = append(files, datedFile{m, d})
	}
	slices.SortFunc(files, func(a, b datedFile) int { return a.day.Compare(b.day) })

	return files
}

// Family lists the log at path with its dated files, oldest first. The live
// file comes last, and is listed only when it exists.
func Family(path string) []string {
	var out []string
	for _, f := range dated(path) {
		out = append(out, f.path)
	}
	if _, err := os.Stat(path); err == nil {
		out = append(out, path)
	}

	return out
}
