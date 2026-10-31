<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

/** What a bridge can take changed for these projects: it came back, paused or resumed, reported other work kinds, or reported other repositories. Dispatched after the commit, with ids only. */
final readonly class BridgeAvailabilityChanged
{
    /** @param list<string> $projectIds RFC 4122 ids of every project the bridge follows or followed */
    public function __construct(
        public array $projectIds,
    ) {
    }
}
