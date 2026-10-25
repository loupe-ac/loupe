<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Notification\Repository\NotificationPreferenceRepository;

/** The file holds the choices that differ from their default. */
final readonly class NotificationPreferenceExporter implements UserDataExporterInterface
{
    public function __construct(
        private NotificationPreferenceRepository $notificationPreferences,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'notification_preferences.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->notificationPreferences->findByOwner($user) as $preference) {
            yield [
                'event' => $preference->event->value,
                'channel' => $preference->channel->value,
                'enabled' => $preference->enabled,
            ];
        }
    }
}
