<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxDecision;

/** The changed decision, and whether to offer a follow-up card, because the card is finished. */
final readonly class ChangeInboxDecisionView
{
    public function __construct(
        public InboxDecision $decision,
        public bool $offersFollowUp,
    ) {
    }
}
