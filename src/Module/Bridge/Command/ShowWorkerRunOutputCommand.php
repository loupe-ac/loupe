<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

final readonly class ShowWorkerRunOutputCommand
{
    public function __construct(
        public WorkerRun $run,
        /** The byte offset to read from. */
        public int $from,
    ) {
    }
}
