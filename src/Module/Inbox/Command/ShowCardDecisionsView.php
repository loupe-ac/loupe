<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxDecision;

/** The decisions of a card, newest first. */
final readonly class ShowCardDecisionsView
{
    /** @param list<InboxDecision> $decisions */
    public function __construct(
        public Card $card,
        public array $decisions,
    ) {
    }
}
