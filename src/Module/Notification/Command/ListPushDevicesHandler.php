<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\PushAvailability;

final readonly class ListPushDevicesHandler
{
    public function __construct(
        private PushDeviceRepository $pushDevices,
        private PushAvailability $availability,
    ) {
    }

    public function __invoke(ListPushDevicesCommand $command): ListPushDevicesView
    {
        $devices = $this->pushDevices->findByOwner($command->user);

        $currentId = null;
        if (null !== $command->currentTokenHash) {
            foreach ($devices as $device) {
                if ($device->deviceTokenHash === $command->currentTokenHash) {
                    $currentId = (string) $device->id;
                    break;
                }
            }
        }

        $reason = $this->availability->reason();

        return new ListPushDevicesView($devices, $currentId, $reason, null === $reason);
    }
}
