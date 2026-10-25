<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class RemovePushDeviceCommand
{
    public function __construct(
        public User $user,
        public Uuid $deviceId,
    ) {
    }
}
