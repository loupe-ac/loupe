<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use Symfony\Component\Uid\Uuid;

/** What the adoption of one legacy run decided under the project lock. The writes of other modules follow the commit. */
final readonly class LegacyDiscoveryRunStep
{
    /** @param list<Uuid> $copies the card requests to cancel */
    public function __construct(
        public bool $moved = false,
        public array $copies = [],
        /** The rule of the request to open, or null to open none. */
        public ?string $openRuleId = null,
    ) {
    }
}
