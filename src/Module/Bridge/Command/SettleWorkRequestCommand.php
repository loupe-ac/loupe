<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\UsageLimitHandBack;
use App\Module\Bridge\ValueObject\WorkRequestState;
use Symfony\Component\Uid\Uuid;

final readonly class SettleWorkRequestCommand
{
    /**
     * @param WorkRequestState        $state    done or refused, or open when the bridge gives the request back
     * @param string|null             $reason   a code that matches WorkRequest::REASON_PATTERN
     * @param UsageLimitHandBack|null $handBack what a bridge says when it gives the request back. Required for the state open.
     */
    public function __construct(
        public User $owner,
        public Uuid $bridgeId,
        public Uuid $workRequestId,
        public Uuid $claimToken,
        public WorkRequestState $state,
        public ?string $reason,
        public ?UsageLimitHandBack $handBack = null,
    ) {
    }
}
