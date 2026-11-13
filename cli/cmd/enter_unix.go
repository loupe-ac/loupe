//go:build unix

package cmd

import (
	"io"
	"os"
	"sync"
	"time"

	"golang.org/x/sys/unix"
	"golang.org/x/term"
)

// enterWatcher reports the first Enter that the person presses on a terminal.
// It polls with a short timeout and reads only when input is waiting, so it
// never leaves a read pending. Stop returns after its last poll, and the
// terminal then holds every key that was pressed afterwards, for the agent.
type enterWatcher struct {
	pressed chan struct{}
	stop    chan struct{}
	done    chan struct{}
	once    sync.Once
}

const enterPollEvery = 50 * time.Millisecond

// watchEnter starts a watcher on in. It returns nil when in is no terminal
// file, which has no Enter to watch for.
func watchEnter(in io.Reader) *enterWatcher {
	f, ok := in.(*os.File)
	if !ok || !term.IsTerminal(int(f.Fd())) {
		return nil
	}
	w := &enterWatcher{pressed: make(chan struct{}), stop: make(chan struct{}), done: make(chan struct{})}
	go w.run(int(f.Fd()))

	return w
}

func (w *enterWatcher) run(fd int) {
	defer close(w.done)
	buf := make([]byte, 256)
	for {
		select {
		case <-w.stop:
			return
		default:
		}
		fds := []unix.PollFd{{Fd: int32(fd), Events: unix.POLLIN}}
		n, err := unix.Poll(fds, int(enterPollEvery/time.Millisecond))
		if err == unix.EINTR {
			continue
		}
		if err != nil {
			return
		}
		if n == 0 || fds[0].Revents&(unix.POLLIN|unix.POLLHUP) == 0 {
			continue
		}
		select {
		case <-w.stop:
			return
		default:
		}
		read, err := unix.Read(fd, buf)
		if err != nil || read == 0 {
			return
		}
		for _, b := range buf[:read] {
			if b == '\n' || b == '\r' {
				close(w.pressed)

				return
			}
		}
	}
}

// Pressed is closed when the person pressed Enter. A nil watcher never fires.
func (w *enterWatcher) Pressed() <-chan struct{} {
	if w == nil {
		return nil
	}

	return w.pressed
}

// Stop ends the watcher and waits until it no longer touches the terminal.
func (w *enterWatcher) Stop() {
	if w == nil {
		return
	}
	w.once.Do(func() { close(w.stop) })
	<-w.done
}
