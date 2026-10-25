<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Entity\PushDevice;

final readonly class ListPushDeviceWarningsView
{
    /** @param list<PushDevice> $devices stopped devices with an open warning, the longest stopped first */
    public function __construct(
        public array $devices,
    ) {
    }
}
