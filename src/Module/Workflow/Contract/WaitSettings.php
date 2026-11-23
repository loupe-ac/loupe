<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What the template allows a worker to wait for: the longest wait, and the most waits in one chain of resumed runs. */
final readonly class WaitSettings
{
    public const int DEFAULT_MAX_MINUTES = 120;
    public const int DEFAULT_MAX_PER_ROUND = 10;
    public const int LEAST_MAX_MINUTES = 1;
    public const int MOST_MAX_MINUTES = 1440;
    public const int LEAST_MAX_PER_ROUND = 1;
    public const int MOST_MAX_PER_ROUND = 100;

    public function __construct(
        public int $maxMinutes = self::DEFAULT_MAX_MINUTES,
        public int $maxPerRound = self::DEFAULT_MAX_PER_ROUND,
    ) {
    }
}
