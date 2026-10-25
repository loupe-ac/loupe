<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationChoice;
use App\Module\Notification\NotificationEvent;

final readonly class ListNotificationPreferencesView
{
    /**
     * @param list<NotificationEvent>   $events   one row of the grid each
     * @param list<NotificationChannel> $channels one column of the grid each
     * @param list<NotificationChoice>  $cells    every cell, in grid order
     */
    public function __construct(
        public array $events,
        public array $channels,
        public array $cells,
    ) {
    }
}
