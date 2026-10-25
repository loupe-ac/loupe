<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Account\Entity\User;

final readonly class RegisterPushDeviceCommand
{
    public function __construct(
        public User $user,
        public string $endpoint,
        public string $publicKey,
        public string $authToken,
        public string $contentEncoding = 'aes128gcm',
        public ?string $label = null,
        public ?string $userAgent = null,
        public string $deviceTokenHash = '',
    ) {
    }
}
