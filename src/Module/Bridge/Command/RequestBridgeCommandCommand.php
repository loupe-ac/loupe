<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use Symfony\Component\Uid\Uuid;

final readonly class RequestBridgeCommandCommand
{
    public function __construct(
        public WorkerRun $run,
        public BridgeCommandKind $kind,
        /** Null when Loupe asks, such as on the close of an ask. */
        public ?User $requestedBy,
        public ?string $reason = null,
        public BridgeCommandCause $cause = BridgeCommandCause::Person,
        public ?Uuid $inboxActionId = null,
        public ?int $inboxItemNumber = null,
        public ?Uuid $inboxItemId = null,
    ) {
    }
}
