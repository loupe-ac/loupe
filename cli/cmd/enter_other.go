//go:build !unix

package cmd

import "io"

// enterWatcher has no terminal reader outside Unix. Ctrl-C still ends the wait.
type enterWatcher struct{}

func watchEnter(io.Reader) *enterWatcher { return nil }

// Pressed never fires.
func (w *enterWatcher) Pressed() <-chan struct{} { return nil }

// Stop does nothing.
func (w *enterWatcher) Stop() {}
