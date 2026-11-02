<?php

declare(strict_types=1);

namespace App\Api\Idempotency\Command;

use App\Api\Idempotency\Repository\IdempotencyRecordRepository;
use Psr\Clock\ClockInterface;

/** Frees every key whose 24 hours ended. This is the only release of a claim that never finished. */
final readonly class PurgeExpiredIdempotencyRecordsHandler
{
    public function __construct(
        private IdempotencyRecordRepository $idempotencyRecords,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeExpiredIdempotencyRecordsCommand $command): int
    {
        return $this->idempotencyRecords->deleteExpired($this->clock->now());
    }
}
