<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Outbox\ActivityEntry;

final readonly class ListActivityRowsView
{
    /**
     * @param list<ActivityEntry> $entries       the shown rows that still match
     * @param list<string>        $removedIds    the shown rows that no longer exist or match
     * @param int                 $filteredTotal how many events of the project match the filters
     */
    public function __construct(
        public array $entries,
        public array $removedIds,
        public int $newerCount,
        public int $filteredTotal,
    ) {
    }
}
