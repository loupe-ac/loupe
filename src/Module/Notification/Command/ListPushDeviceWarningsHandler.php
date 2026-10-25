<?php

declare(strict_types=1);

namespace App\Module\Notification\Command;

use App\Module\Notification\Repository\PushDeviceRepository;

final readonly class ListPushDeviceWarningsHandler
{
    public function __construct(
        private PushDeviceRepository $pushDevices,
    ) {
    }

    public function __invoke(ListPushDeviceWarningsCommand $command): ListPushDeviceWarningsView
    {
        return new ListPushDeviceWarningsView($this->pushDevices->findWarningByOwner($command->user));
    }
}
