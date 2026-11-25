<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;

final readonly class MarkCardDecisionsSeenCommand
{
    public function __construct(
        public Card $card,
        public User $viewer,
        public \DateTimeImmutable $through,
    ) {
    }
}
