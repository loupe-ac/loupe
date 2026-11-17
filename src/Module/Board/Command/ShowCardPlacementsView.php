<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** The placements of the cards a page asked for, in the order it asked. */
final readonly class ShowCardPlacementsView
{
    public function __construct(
        /** @var array<string, CardPlacementView> card id => placement; a card that failed to place has no entry */
        public array $placements,
    ) {
    }
}
