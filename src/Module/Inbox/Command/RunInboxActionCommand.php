<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;

final readonly class RunInboxActionCommand
{
    public function __construct(
        public InboxItem $item,
        public InboxActionKind $kind,
        public string $note,
        public User $user,
    ) {
    }
}
