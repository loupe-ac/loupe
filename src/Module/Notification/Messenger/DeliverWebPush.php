<?php

declare(strict_types=1);

namespace App\Module\Notification\Messenger;

/** One push to one device. A retry then touches that device alone. */
final readonly class DeliverWebPush
{
    public function __construct(
        public string $deviceId,
        public string $payload,
        public string $urgency = 'high',
        public int $ttl = 604800,
    ) {
    }
}
