<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use Symfony\Component\Uid\Uuid;

/**
 * A person or a change of the card ended the wait of a worker. Dispatched before the flush
 * that stores the end, so a listener's rows commit with it and need no flush of their own.
 */
final readonly class WorkerWaitEnded
{
    public function __construct(
        public Uuid $cardId,
        public Uuid $runId,
        public WorkerWaitEndReason $reason,
        public \DateTimeImmutable $endedAt,
    ) {
    }
}
