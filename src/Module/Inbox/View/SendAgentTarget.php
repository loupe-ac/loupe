<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use Symfony\Component\Uid\Uuid;

/** The card that Send an agent works on, and the kind of work that does the stage of the card. */
final readonly class SendAgentTarget
{
    public function __construct(
        public Uuid $cardId,
        public string $kind,
    ) {
    }
}
