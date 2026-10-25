<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Account\Entity\User;

final readonly class ListOpenPushTagsCommand
{
    public function __construct(
        public User $user,
    ) {
    }
}
