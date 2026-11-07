<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

/** A batch of tool calls was stored for a run. Dispatched after the commit, with ids only. */
final readonly class WorkerRunToolCallsRecorded
{
    public function __construct(
        public string $projectId,
    ) {
    }
}
