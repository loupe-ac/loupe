<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Service\PushUnavailableReason;

final readonly class ListPushDevicesView
{
    /** @param list<PushDevice> $devices oldest first */
    public function __construct(
        public array $devices,
        public ?string $currentDeviceId,
        public ?PushUnavailableReason $unavailableReason,
        public bool $available,
    ) {
    }
}
