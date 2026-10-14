<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use Symfony\Component\Uid\Uuid;

final readonly class CancelBridgeCommandCommand
{
    public function __construct(
        public WorkerRun $run,
        public User $requestedBy,
        /** When set, the cancel applies only if the pending command has this id. */
        public ?Uuid $commandId = null,
    ) {
    }
}
