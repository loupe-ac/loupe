<?php

declare(strict_types=1);

namespace App\Module\Bridge\Scheduler;

use App\Module\Bridge\Command\AnnounceQuietBridgesCommand;
use App\Module\Bridge\Command\AnnounceQuietBridgesHandler;
use App\Module\Bridge\Command\CloseStaleInteractiveRunsCommand;
use App\Module\Bridge\Command\CloseStaleInteractiveRunsHandler;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsCommand;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Times out the open runs of quiet bridges every minute, closes the stale interactive runs, and tells the pages of
 * a bridge that just went quiet to reload. `app:time-out-worker-runs` is the manual backstop of the timeouts.
 */
#[AsCronTask('%app.bridge.run_timeout_schedule%')]
final readonly class TimeOutQuietWorkerRunsTask
{
    public function __construct(
        private TimeOutQuietWorkerRunsHandler $timeOutQuietWorkerRuns,
        private AnnounceQuietBridgesHandler $announceQuietBridges,
        private CloseStaleInteractiveRunsHandler $closeStaleInteractiveRuns,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $timedOut = \count(($this->timeOutQuietWorkerRuns)(new TimeOutQuietWorkerRunsCommand()));

        // A tick that finds nothing is the common case, and a line per minute would bury the worker log.
        if ($timedOut > 0) {
            $this->logger->info('bridge.worker_runs_timed_out', ['timedOut' => $timedOut]);
        }

        $stale = \count(($this->closeStaleInteractiveRuns)(new CloseStaleInteractiveRunsCommand()));
        if ($stale > 0) {
            $this->logger->info('bridge.interactive_runs_closed_stale', ['closed' => $stale]);
        }

        ($this->announceQuietBridges)(new AnnounceQuietBridgesCommand());
    }
}
