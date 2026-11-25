<?php

declare(strict_types=1);

namespace App\Module\Readiness\Messenger;

/**
 * Asks for the work that revises a discovery report. The verdict dispatches it, and the transport row
 * commits with the verdict, so no bridge hears of the work before the verdict is saved.
 */
final readonly class RequestDiscoveryRevision
{
    public function __construct(
        public string $runId,
        /** The request the run held at the verdict. A request of the revision before it can still be live. */
        public ?string $previousWorkRequestId,
    ) {
    }
}
