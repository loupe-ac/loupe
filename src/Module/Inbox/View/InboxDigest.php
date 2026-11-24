<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Board\Entity\Card;

/** The decisions of one card that the viewer has not seen since they last opened its Decisions tab. */
final readonly class InboxDigest
{
    public function __construct(
        public Card $card,
        public int $count,
        public \DateTimeImmutable $newest,
    ) {
    }
}
