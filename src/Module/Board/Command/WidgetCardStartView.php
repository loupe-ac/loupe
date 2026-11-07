<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;

final readonly class WidgetCardStartView
{
    /** @param list<BoardColumn> $moves the columns the reviewer may move the card to now */
    public function __construct(
        public Card $card,
        public array $moves,
    ) {
    }
}
