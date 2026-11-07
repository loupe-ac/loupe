package transcript

import (
	"time"
)

// Turn is the input side of one assistant message.
type Turn struct {
	Input, CacheRead, CacheWrite int64
}

// ResumeGap is the pause before one worker process: when the session last
// answered before it, when it first answered, and what that first message
// read and wrote.
type ResumeGap struct {
	LastTurnAt, FirstTurnAt time.Time
	FirstTurn               Turn
}

// Gap is the time the session paused.
func (g ResumeGap) Gap() time.Duration {
	return g.FirstTurnAt.Sub(g.LastTurnAt)
}

// Cold says the first message wrote more to the cache than it read from the
// input and the cache together, so the cache had gone cold.
func (g ResumeGap) Cold() bool {
	return g.FirstTurn.CacheWrite > g.FirstTurn.Input+g.FirstTurn.CacheRead
}

// wakeLine is one assistant line of the transcript.
type wakeLine struct {
	id string
	at time.Time
	t  Turn
}

func wakeLines(path string) ([]wakeLine, error) {
	var lines []wakeLine
	err := eachLine(path, `"assistant"`, func(line []byte) {
		id, m, at, ok := decodeAssistant(line)
		if ok {
			lines = append(lines, wakeLine{id, at, Turn{m.input, m.cacheRead, m.write5m + m.write1h}})
		}
	})

	return lines, err
}

// LastAssistantAt is the time of the last assistant message of the
// transcript, and the zero time when it holds none. A usage limit notice is no
// message of the model, so it does not count.
func LastAssistantAt(path string) (time.Time, error) {
	lines, err := wakeLines(path)
	if err != nil {
		return time.Time{}, err
	}
	var last time.Time
	for _, l := range lines {
		if l.at.After(last) {
			last = l.at
		}
	}

	return last, nil
}

// ResumeGaps gives the pause before each worker process that ran in windows,
// and nil for a process with no assistant message before it or in it. The
// first message of a process takes the largest count of each field over the
// lines that repeat its id, as a streamed message grows.
func ResumeGaps(path string, windows []Window) ([]*ResumeGap, error) {
	limits, err := windowLimits(windows)
	if err != nil {
		return nil, err
	}
	lines, err := wakeLines(path)
	if err != nil {
		return nil, err
	}

	out := make([]*ResumeGap, len(windows))
	for i, w := range windows {
		inside := func(at time.Time) bool {
			return !at.Before(w.Start) && (limits[i].IsZero() || at.Before(limits[i]))
		}
		var gap ResumeGap
		first := -1
		for j, l := range lines {
			switch {
			case l.at.Before(w.Start):
				if l.at.After(gap.LastTurnAt) {
					gap.LastTurnAt = l.at
				}
			case first < 0 && inside(l.at):
				first = j
			}
		}
		if first < 0 || gap.LastTurnAt.IsZero() {
			continue
		}
		gap.FirstTurnAt = lines[first].at
		for _, l := range lines[first:] {
			if l.id != lines[first].id || !inside(l.at) {
				continue
			}
			gap.FirstTurn.Input = max(gap.FirstTurn.Input, l.t.Input)
			gap.FirstTurn.CacheRead = max(gap.FirstTurn.CacheRead, l.t.CacheRead)
			gap.FirstTurn.CacheWrite = max(gap.FirstTurn.CacheWrite, l.t.CacheWrite)
			if l.at.Before(gap.FirstTurnAt) {
				gap.FirstTurnAt = l.at
			}
		}
		out[i] = &gap
	}

	return out, nil
}
