<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Notification\Repository\PushDeviceRepository;

/** The endpoint and the keys are credentials to send a push, so the file names no address and no key. */
final readonly class PushDeviceExporter implements UserDataExporterInterface
{
    public function __construct(
        private PushDeviceRepository $pushDevices,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'push_devices.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->pushDevices->findByOwner($user) as $device) {
            yield [
                'label' => $device->label,
                'createdAt' => $device->createdAt->format(\DATE_ATOM),
                'lastDeliveredAt' => $device->lastDeliveredAt?->format(\DATE_ATOM),
                'stoppedAt' => $device->stoppedAt?->format(\DATE_ATOM),
            ];
        }
    }
}
