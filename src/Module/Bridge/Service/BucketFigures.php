<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

/** What one run spent in one bucket: main-session time, time with subagents, and calls with subagents. */
final readonly class BucketFigures
{
    public function __construct(
        public int $ms,
        public int $msWithSubagents,
        public int $calls,
    ) {
    }
}
