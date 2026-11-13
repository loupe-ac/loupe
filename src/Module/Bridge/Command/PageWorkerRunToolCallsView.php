<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRunToolCall;

final readonly class PageWorkerRunToolCallsView
{
    /** @param list<WorkerRunToolCall> $calls */
    public function __construct(
        public array $calls,
        public bool $hasMore,
    ) {
    }
}
