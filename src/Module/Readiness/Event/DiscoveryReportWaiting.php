<?php

declare(strict_types=1);

namespace App\Module\Readiness\Event;

use Symfony\Component\Uid\Uuid;

/** A report, new or revised, waits for the verdict of the owner. Dispatched after the commit. */
final readonly class DiscoveryReportWaiting
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $runId,
        public Uuid $documentId,
    ) {
    }
}
