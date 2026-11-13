<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\View\WorkerRunControls;

/** Reads one keyset page of the runs of a project, newest report first. */
final readonly class PageWorkerRunsHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerRunControls $controls,
    ) {
    }

    public function __invoke(PageWorkerRunsCommand $command): PageWorkerRunsView
    {
        $runs = $this->workerRuns->findAfterByProject($command->project, $command->listQuery, $command->afterReceivedAt, $command->afterId, $command->limit + 1);
        $hasMore = \count($runs) > $command->limit;
        $runs = \array_slice($runs, 0, $command->limit);

        $pending = [];
        foreach ($this->controls->forRuns($command->project, $runs) as $runId => $control) {
            if (null !== $control->pendingCommand) {
                $pending[$runId] = $control->pendingCommand;
            }
        }

        return new PageWorkerRunsView($runs, $pending, $hasMore);
    }
}
