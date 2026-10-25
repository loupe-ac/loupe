<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\Service\NotificationPreferences;

final readonly class ListNotificationPreferencesHandler
{
    public function __construct(
        private NotificationPreferences $preferences,
    ) {
    }

    public function __invoke(ListNotificationPreferencesCommand $command): ListNotificationPreferencesView
    {
        return new ListNotificationPreferencesView(NotificationEvent::cases(), NotificationChannel::cases(), $this->preferences->grid($command->user));
    }
}
