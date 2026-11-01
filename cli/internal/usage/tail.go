package usage

import (
	"bytes"
	"io"
	"os"
	"time"
)

// Tail reads the lines that a file gained since the last read. It keeps the
// byte offset of the first line it has not read.
type Tail struct {
	path   func() (string, bool)
	parse  Parser
	offset int64
}

// NewTail makes a tail of the file that path names. path says false while the
// file is not known yet.
func NewTail(path func() (string, bool), parse Parser) *Tail {
	return &Tail{path: path, parse: parse}
}

// Read returns the readings of the new lines. A last line with no newline
// waits for the next read, unless final is true. A file that is missing or
// shorter than the offset reads from its start.
func (t *Tail) Read(now time.Time, final bool) []Reading {
	path, ok := t.path()
	if !ok {
		return nil
	}
	f, err := os.Open(path)
	if err != nil {
		return nil
	}
	defer f.Close()
	if info, err := f.Stat(); err != nil || info.Size() < t.offset {
		t.offset = 0
	}
	if _, err := f.Seek(t.offset, io.SeekStart); err != nil {
		return nil
	}
	data, err := io.ReadAll(f)
	if err != nil {
		return nil
	}
	if !final {
		end := bytes.LastIndexByte(data, '\n')
		if end < 0 {
			return nil
		}
		data = data[:end+1]
	}
	t.offset += int64(len(data))

	var out []Reading
	for line := range bytes.SplitSeq(data, []byte{'\n'}) {
		out = append(out, t.parse(line, now)...)
	}

	return out
}
