<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxItem;

final readonly class CancelInboxActionCommand
{
    public function __construct(
        public InboxAction $action,
        public InboxItem $item,
        public User $user,
    ) {
    }
}
