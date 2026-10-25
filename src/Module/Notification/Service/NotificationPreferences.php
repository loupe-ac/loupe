<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Account\Entity\User;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationChoice;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\Repository\NotificationPreferenceRepository;

/** Reads what a user receives. A cell with no stored row has the default of its event. */
final readonly class NotificationPreferences
{
    public function __construct(
        private NotificationPreferenceRepository $notificationPreferences,
    ) {
    }

    public function isEnabled(User $user, NotificationEvent $event, NotificationChannel $channel): bool
    {
        return $this->notificationPreferences->findOneByCell($user, $event, $channel)->enabled ?? $event->defaultEnabled();
    }

    /** @return list<NotificationChoice> one for every cell of the grid, in grid order */
    public function grid(User $user): array
    {
        $stored = [];
        foreach ($this->notificationPreferences->findByOwner($user) as $preference) {
            $stored[NotificationChoice::key($preference->event, $preference->channel)] = $preference->enabled;
        }

        $cells = [];
        foreach (NotificationEvent::cases() as $event) {
            foreach (NotificationChannel::cases() as $channel) {
                $cells[] = new NotificationChoice($event, $channel, $stored[NotificationChoice::key($event, $channel)] ?? $event->defaultEnabled());
            }
        }

        return $cells;
    }
}
