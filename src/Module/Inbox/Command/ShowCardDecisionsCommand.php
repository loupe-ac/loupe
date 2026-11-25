<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Board\Entity\Card;

final readonly class ShowCardDecisionsCommand
{
    public function __construct(
        public Card $card,
    ) {
    }
}
