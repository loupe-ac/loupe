<?php

declare(strict_types=1);

namespace App\Module\Notification;

/** A kind of event the owner can ask to hear about. The grid draws one row per case. */
enum NotificationEvent: string
{
    case BlockingInboxItem = 'blocking-inbox-item';
    case OtherInboxItem = 'other-inbox-item';

    /** What a user gets until they flip the switch. A stored row exists only for a change of this. */
    public function defaultEnabled(): bool
    {
        return match ($this) {
            self::BlockingInboxItem => true,
            self::OtherInboxItem => false,
        };
    }

    public function labelKey(): string
    {
        return 'notification.event.'.$this->keyName().'.label';
    }

    public function descriptionKey(): string
    {
        return 'notification.event.'.$this->keyName().'.description';
    }

    private function keyName(): string
    {
        return str_replace('-', '_', $this->value);
    }
}
