package transcript

import (
	"strconv"
	"testing"
	"time"
)

// turnLine is an assistant line at minute with the given input counts.
func turnLine(minute int, id string, input, read, write int) string {
	return `{"type":"assistant","timestamp":"` + at(minute).Add(2*time.Second).Format(time.RFC3339) + `","message":{"id":"` + id +
		`","model":"claude-opus-5-5","usage":{"input_tokens":` + strconv.Itoa(input) + `,"output_tokens":1,"cache_read_input_tokens":` + strconv.Itoa(read) +
		`,"cache_creation_input_tokens":` + strconv.Itoa(write) + `}}}`
}

func TestLastAssistantAtIsTheLastMessageOfTheModel(t *testing.T) {
	notice := `{"type":"assistant","timestamp":"` + at(40).Format(time.RFC3339) + `","message":{"id":"x","model":"<synthetic>","usage":{"input_tokens":0}}}`
	path := writeTranscript(t, userLine(0), turnLine(0, "a", 1, 2, 3), userLine(9), turnLine(9, "b", 1, 2, 3), notice)

	got, err := LastAssistantAt(path)
	if err != nil {
		t.Fatal(err)
	}
	if want := at(9).Add(2 * time.Second); !got.Equal(want) {
		t.Fatalf("LastAssistantAt = %s, want %s", got, want)
	}

	empty := writeTranscript(t, userLine(0))
	if got, err := LastAssistantAt(empty); err != nil || !got.IsZero() {
		t.Fatalf("LastAssistantAt of no message = %s, %v", got, err)
	}
}

// The first message after a pause repeats its line as it grows, so it takes
// the largest count of each field. A later line with the same id outside the
// process does not count, and the first process has no pause.
func TestResumeGapsTakeTheLargestCountsOfTheFirstMessage(t *testing.T) {
	path := writeTranscript(t,
		userLine(0), turnLine(0, "a", 1, 10, 20),
		userLine(30),
		turnLine(30, "b", 2, 100, 900), turnLine(30, "b", 2, 300, 500), turnLine(30, "b", 7, 250, 1200),
		turnLine(31, "c", 50, 50, 50),
		userLine(90), turnLine(90, "d", 3, 4, 50),
	)

	got, err := ResumeGaps(path, started(at(0), at(30), at(90)))
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 3 || got[0] != nil {
		t.Fatalf("gaps = %+v", got)
	}
	g := got[1]
	if !g.LastTurnAt.Equal(at(0).Add(2*time.Second)) || !g.FirstTurnAt.Equal(at(30).Add(2*time.Second)) ||
		g.FirstTurn != (Turn{Input: 7, CacheRead: 300, CacheWrite: 1200}) || g.Gap() != 30*time.Minute || !g.Cold() {
		t.Fatalf("gap 1 = %+v", g)
	}
	if !got[2].LastTurnAt.Equal(at(31).Add(2*time.Second)) || got[2].Gap() != 59*time.Minute || !got[2].Cold() {
		t.Fatalf("gap 2 = %+v (cold %v)", got[2], got[2].Cold())
	}
}

// A process that wrote no message has no gap, and neither has one whose
// windows overlap.
func TestResumeGapsSkipAProcessWithNoMessage(t *testing.T) {
	path := writeTranscript(t, userLine(0), turnLine(0, "a", 1, 2, 3), userLine(10))
	got, err := ResumeGaps(path, started(at(0), at(10)))
	if err != nil || len(got) != 2 || got[0] != nil || got[1] != nil {
		t.Fatalf("gaps = %+v, %v", got, err)
	}

	if _, err := ResumeGaps(path, started(at(10), at(0))); err == nil {
		t.Fatal("overlapping windows gave no error")
	}
}
