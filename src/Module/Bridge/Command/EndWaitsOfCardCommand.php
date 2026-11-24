<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use Symfony\Component\Uid\Uuid;

final readonly class EndWaitsOfCardCommand
{
    public function __construct(
        public Uuid $cardId,
        public WorkerWaitEndReason $reason,
    ) {
    }
}
