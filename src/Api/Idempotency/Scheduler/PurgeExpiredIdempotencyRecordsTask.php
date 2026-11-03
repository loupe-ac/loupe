<?php

declare(strict_types=1);

namespace App\Api\Idempotency\Scheduler;

use App\Api\Idempotency\Command\PurgeExpiredIdempotencyRecordsCommand;
use App\Api\Idempotency\Command\PurgeExpiredIdempotencyRecordsHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCronTask('17 * * * *')]
final readonly class PurgeExpiredIdempotencyRecordsTask
{
    public function __construct(
        private PurgeExpiredIdempotencyRecordsHandler $purge,
    ) {
    }

    public function __invoke(): void
    {
        ($this->purge)(new PurgeExpiredIdempotencyRecordsCommand());
    }
}
