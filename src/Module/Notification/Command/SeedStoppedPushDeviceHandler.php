<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Entity\PushDevice;
use Doctrine\ORM\EntityManagerInterface;

/** Dev and e2e only: a push device that the push service refused, without a push to refuse. */
final readonly class SeedStoppedPushDeviceHandler
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SeedStoppedPushDeviceCommand $command): PushDevice
    {
        $device = new PushDevice(
            $command->user,
            'https://web.push.apple.com/seed-'.bin2hex(random_bytes(16)),
            'seeded-public-key',
            'seeded-auth-token',
            'aes128gcm',
            $command->label,
            bin2hex(random_bytes(32)),
        );
        $device->stoppedAt = $command->stoppedAt;
        if ($command->dismissed) {
            $device->warningDismissedAt = $command->stoppedAt->modify('+1 hour');
        }
        $this->em->persist($device);
        $this->em->flush();

        return $device;
    }
}
