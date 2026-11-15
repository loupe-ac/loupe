<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;

final readonly class ListTerminalColumnRowsView
{
    /**
     * @param list<Card>   $items   the shown rows that are still in the column
     * @param list<string> $goneIds the shown rows that left the column
     */
    public function __construct(
        public array $items,
        public array $goneIds,
        /** Cards that completed after the page rendered. */
        public int $newerCount,
        /** Every card the column holds now. */
        public int $total,
    ) {
    }
}
