<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

final readonly class PageWorkerRunToolCallsCommand
{
    public function __construct(
        public WorkerRun $run,
        public int $limit,
        /** The sequence number of the last call of the previous page. Zero for the first page. */
        public int $afterSeq = 0,
    ) {
    }
}
