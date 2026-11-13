<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

final readonly class PageBridgeHostSamplesCommand
{
    public function __construct(
        public WorkerRun $run,
        public int $limit,
        /** The time of the last sample of the previous page. Null for the first page. */
        public ?\DateTimeImmutable $after = null,
    ) {
    }
}
