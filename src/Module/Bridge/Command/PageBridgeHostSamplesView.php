<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\ValueObject\BridgeHostSampleReport;

final readonly class PageBridgeHostSamplesView
{
    /** @param list<BridgeHostSampleReport> $samples */
    public function __construct(
        public array $samples,
        public bool $hasMore,
        /** Why the list is empty when the run has no bridge or no start. */
        public ?string $note = null,
    ) {
    }
}
