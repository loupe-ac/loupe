<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Utils\RowChanges;

final readonly class ListTerminalColumnRowsView
{
    /**
     * @param list<Card>                   $items   the shown rows that are still in the column
     * @param list<string>                 $goneIds the shown rows that left the column
     * @param ?RowChanges<Card>            $changes what page one changes on the screen, or null on a later page
     * @param ?ListTerminalColumnCardsView $page    page one as the list reads it, or null on a later page
     */
    public function __construct(
        public array $items,
        public array $goneIds,
        /** Cards that completed after the page rendered. */
        public int $newerCount,
        /** Every card the column holds now. */
        public int $total,
        public ?RowChanges $changes = null,
        public ?ListTerminalColumnCardsView $page = null,
    ) {
    }
}
