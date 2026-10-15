<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunRepository;

/** How many runs of the project are open, for the sidebar pill of the Activity link. */
final readonly class ShowActivityOpenCountHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
    ) {
    }

    public function __invoke(ShowActivityOpenCountCommand $command): int
    {
        return $this->workerRuns->countOpenOfProject($command->project);
    }
}
