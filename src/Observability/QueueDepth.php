<?php

declare(strict_types=1);

namespace App\Observability;

final readonly class QueueDepth
{
    public function __construct(
        public string $queueName,
        public string $transport,
        public int $pending,
        public int $oldestAgeSeconds,
    ) {
    }
}
