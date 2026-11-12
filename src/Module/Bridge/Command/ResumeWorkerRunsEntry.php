<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

/** One run of a resume batch, as the caller named it. The run is null when the caller may not act on it. */
final readonly class ResumeWorkerRunsEntry
{
    public function __construct(
        public string $runId,
        public ?WorkerRun $run,
    ) {
    }
}
