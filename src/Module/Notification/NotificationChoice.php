<?php

declare(strict_types=1);

namespace App\Module\Notification;

/** One cell of the grid, as the owner set it. */
final readonly class NotificationChoice
{
    public function __construct(
        public NotificationEvent $event,
        public NotificationChannel $channel,
        public bool $enabled,
    ) {
    }

    public function fieldName(): string
    {
        return self::key($this->event, $this->channel);
    }

    public static function key(NotificationEvent $event, NotificationChannel $channel): string
    {
        return $event->value.'__'.$channel->value;
    }
}
