<?php

declare(strict_types=1);

namespace App\Module\Notification;

/** A way to reach the owner. The grid draws one column per case. */
enum NotificationChannel: string
{
    case WebPush = 'web-push';

    public function labelKey(): string
    {
        return 'notification.channel.'.str_replace('-', '_', $this->value).'.label';
    }
}
