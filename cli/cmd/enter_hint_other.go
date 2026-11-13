//go:build !unix

package cmd

func stopWaitHint() string { return "Press Ctrl-C to stop waiting." }
