<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Utils\RowChanges;

final readonly class ListBacklogRowsView
{
    /**
     * @param list<Card>            $items           the shown rows that still match, as the page shows them
     * @param list<string>          $goneIds         the shown rows that left the Backlog or stopped matching
     * @param array<string, int>    $pendingComments pending feedback per card id
     * @param list<BoardColumn>     $boardColumns
     * @param list<Card>            $epics           the epics of the Backlog cards, for the epic filter
     * @param ?RowChanges<Card>     $changes         what page one changes on the screen, or null on a later page
     * @param ?ListBacklogCardsView $page            page one as the list reads it, or null on a later page
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
        public ?RowChanges $changes = null,
        public ?ListBacklogCardsView $page = null,
    ) {
    }
}
