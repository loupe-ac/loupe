<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Readiness\Entity\DiscoveryRun;
use Symfony\Component\Uid\Uuid;

final readonly class FailDiscoveryRunCommand
{
    public function __construct(
        public DiscoveryRun $run,
        /** A short code such as no-taker, or the failure reason a bridge reported. */
        public string $reason,
        /** The rule whose work ended. A run fails only while it waits for that rule's work, and null accepts either. */
        public ?string $ruleId = null,
        /** The work request that ended, or null when none did. A run does not fail for a request that a report answered. */
        public ?Uuid $workRequestId = null,
    ) {
    }
}
