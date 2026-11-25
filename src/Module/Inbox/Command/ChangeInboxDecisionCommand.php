<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxDecision;

final readonly class ChangeInboxDecisionCommand
{
    public function __construct(
        public InboxDecision $decision,
        public User $user,
        /** One of the other options, or the owner's own words ("Something else"). */
        public string $choice,
        public ?string $note = null,
    ) {
    }
}
