<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

/** The billed cost of a run reached a new state. Dispatched after the commit, with ids only. */
final readonly class WorkerRunBilledCostRecorded
{
    public function __construct(
        public string $projectId,
    ) {
    }
}
