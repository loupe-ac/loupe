<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

/** The wake of at least one run was filled in. Dispatched after the commit, with ids only. */
final readonly class WorkerRunWakesRecorded
{
    public function __construct(
        public string $projectId,
    ) {
    }
}
