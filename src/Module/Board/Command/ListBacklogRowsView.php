<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;

final readonly class ListBacklogRowsView
{
    /**
     * @param list<Card>         $items           the shown rows that still match, as the page shows them
     * @param list<string>       $goneIds         the shown rows that left the Backlog or stopped matching
     * @param array<string, int> $pendingComments pending feedback per card id
     * @param list<BoardColumn>  $boardColumns
     * @param list<Card>         $epics           the epics of the Backlog cards, for the epic filter
     */
    public function __construct(
        public array $items,
        public array $goneIds,
        public array $pendingComments,
        public array $boardColumns,
        public ?BoardColumn $nextColumn,
        /** Cards that match the filters and are newer than the page. */
        public int $newerCount,
        public int $total,
        public int $filteredTotal,
        public array $epics,
    ) {
    }
}
