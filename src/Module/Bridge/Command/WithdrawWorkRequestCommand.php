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
        /** Expires only an open request that waited for a bridge since this time or longer, and that no work hold holds. */
        public ?\DateTimeImmutable $deadline = null,
    ) {
    }
}
