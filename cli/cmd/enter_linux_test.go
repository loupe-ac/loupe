//go:build linux

package cmd

import (
	"fmt"
	"os"
	"testing"
	"time"

	"golang.org/x/sys/unix"
)

// openPty opens a pseudo-terminal pair: the master the test types on, and the
// slave the watcher reads, which is a real terminal.
func openPty(t *testing.T) (master, slave *os.File) {
	t.Helper()
	m, err := os.OpenFile("/dev/ptmx", os.O_RDWR|unix.O_NOCTTY, 0)
	if err != nil {
		t.Skipf("no pseudo-terminal: %v", err)
	}
	if err := unix.IoctlSetPointerInt(int(m.Fd()), unix.TIOCSPTLCK, 0); err != nil {
		t.Skipf("unlock the pseudo-terminal: %v", err)
	}
	n, err := unix.IoctlGetInt(int(m.Fd()), unix.TIOCGPTN)
	if err != nil {
		t.Skipf("name the pseudo-terminal: %v", err)
	}
	s, err := os.OpenFile(fmt.Sprintf("/dev/pts/%d", n), os.O_RDWR|unix.O_NOCTTY, 0)
	if err != nil {
		t.Skipf("open the slave: %v", err)
	}
	t.Cleanup(func() { _ = s.Close(); _ = m.Close() })

	return m, s
}

func TestEnterWatcherSeesEnterOnATerminal(t *testing.T) {
	master, slave := openPty(t)
	w := watchEnter(slave)
	if w == nil {
		t.Fatal("a terminal gets a watcher")
	}
	defer w.Stop()

	if _, err := master.WriteString("\n"); err != nil {
		t.Fatal(err)
	}
	select {
	case <-w.Pressed():
	case <-time.After(5 * time.Second):
		t.Fatal("Enter was not seen")
	}
}

func TestEnterWatcherLeavesLaterKeysForTheNextReader(t *testing.T) {
	master, slave := openPty(t)
	w := watchEnter(slave)
	if w == nil {
		t.Fatal("a terminal gets a watcher")
	}

	w.Stop()
	if _, err := master.WriteString("x\n"); err != nil {
		t.Fatal(err)
	}
	buf := make([]byte, 8)
	_ = slave.SetReadDeadline(time.Now().Add(5 * time.Second))
	n, err := slave.Read(buf)

	if err != nil || string(buf[:n]) != "x\n" {
		t.Errorf("the next reader got %q, %v; want the keys typed after Stop", buf[:n], err)
	}
}

func TestEnterWatcherIgnoresInputBeforeEnter(t *testing.T) {
	master, slave := openPty(t)
	w := watchEnter(slave)
	defer w.Stop()

	_, _ = master.WriteString("ab")
	select {
	case <-w.Pressed():
		t.Fatal("letters without Enter ended the wait")
	case <-time.After(300 * time.Millisecond):
	}
}

func TestNoWatcherForAReaderThatIsNoTerminal(t *testing.T) {
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer r.Close()
	defer w.Close()

	if watchEnter(r) != nil {
		t.Error("a pipe gets a watcher")
	}
}
