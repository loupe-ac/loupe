package cmd

import (
	"cmp"
	"context"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/rules"
)

// runRuleCommand runs the command of a command rule.
func runRuleCommand(ctx context.Context, spec procSpec, onStart func(workerProc)) procResult {
	return runProc(ctx, commandProc, spec, onStart)
}

// adoptRuleCommand waits for the command another image started in dir.
func adoptRuleCommand(ctx context.Context, dir string) procResult {
	return adoptProc(ctx, commandProc, dir)
}

// isCommand reports whether the run is the run of a command rule.
func (p pending) isCommand() bool {
	return p.action == rules.ActionCommand
}

// execute runs the command of a command rule in the project dir, or for a
// run-command request in the folder of its branch, under the
// rule's timeout, and settles the run. It reports running once the command
// exists, with no session. A person's stop reaches it as it reaches a worker.
// A run-command request streams its output to Loupe while it runs, and the
// last chunk goes out before the run settles.
func (r *router) execute(p pending) {
	began := time.Now()
	streams := p.work.Capability == rules.CapabilityRunCommand
	var stream *outputStreamer
	onStart := func(proc workerProc) {
		began = time.Now()
		r.mu.Lock()
		defer r.mu.Unlock()
		r.trackLocked(liveRun{p: p, began: began, proc: proc})
		r.log.Info("command_started", append(about(p.event, p.rule), "pid", proc.pid)...)
		r.emitLocked(p, api.RunStateReport{State: api.RunRunning, StartedAt: began})
		if streams {
			stream = r.startOutput(p, proc)
		}
		if r.stops[p.runID] {
			r.stopLiveLocked(r.live[p.runID])
		}
	}
	run := r.worker.command
	if run == nil {
		run = runRuleCommand
	}
	dir := p.spec.dir
	if p.work.Capability == rules.CapabilityRunCommand {
		// The folder may be gone since the offer, while the run waited.
		var ok bool
		if dir, ok = r.worktreeDir(r.workerContext(), p.spec.dir, p.work.RunBranch()); !ok {
			failed := workerResult{exitCode: -1, output: rules.RefuseWorktreeMissing + ": no worktree has " + p.work.RunBranch() + " checked out", command: true}
			r.settle(p, endedRun{res: failed, began: began, elapsed: time.Since(began)})

			return
		}
	}
	ctx, cancel := context.WithTimeout(r.workerContext(), cmp.Or(p.spec.command.Timeout, rules.DefaultCommandTimeout))
	res := run(ctx, procSpec{argv: p.spec.command.Argv, dir: dir, runID: p.runID, merge: streams}, onStart)
	cancel()
	stream.finish()
	r.settle(p, endedRun{res: commandResult(res), began: began, elapsed: time.Since(began)})
}

// adoptCommandLocked waits for the command of a run a former image started,
// on its own goroutine, and settles the run. The caller holds mu and took the
// run's card.
func (r *router) adoptCommandLocked(p pending, run handoverRun) {
	r.trackLocked(liveRun{p: p, began: run.Began, proc: workerProc{pid: run.PID, dir: run.Dir}})
	r.log.Info("command_adopted", append(about(p.event, p.rule), "pid", run.PID)...)

	wait := r.worker.adoptCommand
	if wait == nil {
		wait = adoptRuleCommand
	}
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		res := wait(r.workerContext(), run.Dir)
		r.settle(p, endedRun{res: commandResult(res), began: run.Began, elapsed: time.Since(run.Began)})
	}()
}

// commandResult is how a command run ended, as a worker's end reads it. A
// failed command reports why first, and a command that ran out of time, was
// killed or never started reports exit code -1, as a failed before command
// does.
func commandResult(res procResult) workerResult {
	failure := res.failureOf(commandProc)
	out := workerResult{output: res.output, killed: res.killed && !res.timedOut, timedOut: res.timedOut, dir: res.runDir, command: true}
	if failure == "" {
		return out
	}
	out.exitCode, out.output = res.exitCode, failedOutput(failure, res.output)
	if out.exitCode == 0 || res.err != nil || res.killed || res.timedOut {
		out.exitCode = -1
	}

	return out
}
