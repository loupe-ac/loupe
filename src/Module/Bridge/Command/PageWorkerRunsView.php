<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;

final readonly class PageWorkerRunsView
{
    /**
     * @param list<WorkerRun>              $runs    newest report first
     * @param array<string, BridgeCommand> $pending the command that waits on a run, keyed by the run id
     */
    public function __construct(
        public array $runs,
        public array $pending,
        public bool $hasMore,
    ) {
    }
}
