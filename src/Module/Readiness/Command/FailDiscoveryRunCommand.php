<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Readiness\Entity\DiscoveryRun;

final readonly class FailDiscoveryRunCommand
{
    public function __construct(
        public DiscoveryRun $run,
        /** A short code such as no-taker, or the failure reason a bridge reported. */
        public string $reason,
        /** The rule whose work ended. A run fails only while it waits for that rule's work, and null accepts either. */
        public ?string $ruleId = null,
    ) {
    }
}
