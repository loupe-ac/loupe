<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

/** One run of a resume batch, as the caller named it. The resolver returns null when the caller may not act on the run. */
final readonly class ResumeWorkerRunsEntry
{
    /** @param \Closure(): ?WorkerRun $resolve */
    public function __construct(
        public string $runId,
        public \Closure $resolve,
    ) {
    }
}
