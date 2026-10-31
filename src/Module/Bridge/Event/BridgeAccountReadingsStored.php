<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

/** A heartbeat stored at least one new account reading for these projects. Dispatched after the commit, with ids only. */
final readonly class BridgeAccountReadingsStored
{
    /** @param list<string> $projectIds RFC 4122 ids of every project the bridge follows or followed */
    public function __construct(
        public array $projectIds,
    ) {
    }
}
