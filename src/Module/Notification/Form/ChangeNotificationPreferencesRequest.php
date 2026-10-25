<?php

declare(strict_types=1);

namespace App\Module\Notification\Form;

use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationChoice;
use App\Module\Notification\NotificationEvent;

class ChangeNotificationPreferencesRequest
{
    /**
     * @param array<string, bool>        $choices keyed by NotificationChoice::key(), one entry for each cell of the grid
     * @param array<string, string|null> $shown   the same keys, '1' or '0' for the value the page showed
     */
    public function __construct(
        public array $choices = [],
        public array $shown = [],
    ) {
    }

    /** @param list<NotificationChoice> $cells */
    public static function fromCells(array $cells): self
    {
        $choices = [];
        $shown = [];
        foreach ($cells as $cell) {
            $choices[$cell->fieldName()] = $cell->enabled;
            $shown[$cell->fieldName()] = $cell->enabled ? '1' : '0';
        }

        return new self($choices, $shown);
    }

    /**
     * Only the cells the owner flipped on this page. A page open in an older tab
     * then cannot undo a choice made since, and a cell the page did not show stays as stored.
     *
     * @return list<NotificationChoice>
     */
    public function toChoices(): array
    {
        $result = [];
        foreach (NotificationEvent::cases() as $event) {
            foreach (NotificationChannel::cases() as $channel) {
                $key = NotificationChoice::key($event, $channel);
                $shown = $this->shown[$key] ?? null;
                if ('1' !== $shown && '0' !== $shown) {
                    continue;
                }
                $enabled = $this->choices[$key] ?? false;
                if ($enabled !== ('1' === $shown)) {
                    $result[] = new NotificationChoice($event, $channel, $enabled);
                }
            }
        }

        return $result;
    }
}
