<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxDecision;

final readonly class CreateDecisionFollowUpCommand
{
    public function __construct(
        public InboxDecision $decision,
        /** @phpstan-var non-empty-string */
        public string $title,
        public string $body,
        /** A card type key of the project. */
        public string $type,
    ) {
    }
}
