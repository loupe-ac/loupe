<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/**
 * What a session wake report did. $runs is null when the owner has no project by that handle.
 * $filled counts the runs that took a wake. It is zero when the counts differ, when two runs
 * started in the same second, or when a last turn came after the start of its run.
 */
final readonly class ReportSessionWakeResult
{
    public function __construct(
        public ?int $runs,
        public int $wakes,
        public int $filled,
        public bool $ambiguousOrder = false,
        public bool $lastTurnAfterStart = false,
    ) {
    }
}
