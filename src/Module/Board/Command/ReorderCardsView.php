<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** The block as placed, with the card just above it and the card just below it. */
final readonly class ReorderCardsView
{
    /** @param list<ReorderedCard> $cards the block, top first */
    public function __construct(
        public ?ReorderedCard $above,
        public array $cards,
        public ?ReorderedCard $below,
    ) {
    }
}
