<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

/** The usage of at least one run changed. Dispatched after the commit, with ids only. */
final readonly class WorkerRunUsageRecorded
{
    public function __construct(
        public string $projectId,
    ) {
    }
}
