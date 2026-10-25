<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Account\Entity\User;
use App\Module\Notification\NotificationChoice;

final readonly class ChangeNotificationPreferencesCommand
{
    /** @param list<NotificationChoice> $choices */
    public function __construct(
        public User $user,
        public array $choices,
    ) {
    }
}
