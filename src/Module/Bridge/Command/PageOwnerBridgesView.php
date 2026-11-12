<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\View\BridgeStatus;

final readonly class PageOwnerBridgesView
{
    /**
     * @param list<Bridge>                $bridges  ordered by id
     * @param array<string, BridgeStatus> $statuses keyed by the RFC 4122 bridge id
     */
    public function __construct(
        public array $bridges,
        public array $statuses,
        public bool $hasMore,
    ) {
    }
}
