<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

/** What a bridge shows about these projects may have changed: it sent a heartbeat, or it went quiet. Dispatched after the commit, with ids only. */
final readonly class BridgeReported
{
    /** @param list<string> $projectIds RFC 4122 ids of every project the bridge follows or followed */
    public function __construct(
        public array $projectIds,
    ) {
    }
}
