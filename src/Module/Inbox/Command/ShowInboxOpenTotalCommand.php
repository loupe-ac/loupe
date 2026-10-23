<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;

final readonly class ShowInboxOpenTotalCommand
{
    public function __construct(
        public User $user,
    ) {
    }
}
