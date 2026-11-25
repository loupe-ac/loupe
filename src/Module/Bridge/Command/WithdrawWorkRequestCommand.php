<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\ValueObject\WorkRequestState;
use Symfony\Component\Uid\Uuid;

final readonly class WithdrawWorkRequestCommand
{
    public function __construct(
        public Uuid $workRequestId,
        /** Cancelled or expired. */
        public WorkRequestState $state,
        /** Leaves a request that a bridge claimed since the caller read it. */
        public bool $onlyIfOpen = false,
        /** A reason code the module of the work reads, such as no-taker. */
        public ?string $reason = null,
        /** Expires only an open request that waited for a bridge since this time or longer. It also applies $outsideWorkHoldOnly. */
        public ?\DateTimeImmutable $deadline = null,
        /** Leaves a request of a project that holds its new work, and a request that still carries the mark of a hold. */
        public bool $outsideWorkHoldOnly = false,
        /** Withdraws only while no bridge can take the request, read under the lock. That reading gives the reason. */
        public bool $onlyWhileUntaken = false,
    ) {
    }
}
