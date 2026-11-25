<?php

declare(strict_types=1);

namespace App\Module\Readiness\Event;

use Symfony\Component\Uid\Uuid;

/** The report of a run waits for no verdict any more, because a newer run replaced it. Dispatched after the commit. */
final readonly class DiscoveryReportWithdrawn
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $runId,
    ) {
    }
}
