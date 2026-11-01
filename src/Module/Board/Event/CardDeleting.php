<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Board\Entity\Card;

/**
 * Dispatched inside DeleteCardHandler's transaction, under the project lock,
 * before the card is removed. The rows that cascade with the card are still
 * readable, so a module that links to the card closes its own rows in a
 * listener. The event class is the module's public API.
 */
final readonly class CardDeleting
{
    public function __construct(
        public Card $card,
    ) {
    }
}
