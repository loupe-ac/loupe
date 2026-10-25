<?php

declare(strict_types=1);

namespace App\Module\Notification\Form;

use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationChoice;
use App\Module\Notification\NotificationEvent;

class ChangeNotificationPreferencesRequest
{
    /** @param array<string, bool> $choices keyed by NotificationChoice::key(), one entry for each cell of the grid */
    public function __construct(
        public array $choices = [],
    ) {
    }

    /** @param list<NotificationChoice> $cells */
    public static function fromCells(array $cells): self
    {
        $choices = [];
        foreach ($cells as $cell) {
            $choices[NotificationChoice::key($cell->event, $cell->channel)] = $cell->enabled;
        }

        return new self($choices);
    }

    /** @return list<NotificationChoice> */
    public function toChoices(): array
    {
        $result = [];
        foreach (NotificationEvent::cases() as $event) {
            foreach (NotificationChannel::cases() as $channel) {
                $result[] = new NotificationChoice($event, $channel, $this->choices[NotificationChoice::key($event, $channel)] ?? false);
            }
        }

        return $result;
    }
}
