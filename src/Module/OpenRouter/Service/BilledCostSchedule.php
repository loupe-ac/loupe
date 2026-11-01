<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service;

/**
 * When the cost reads of a run happen, in minutes after the run ended. The
 * values are estimates until the lag of OpenRouter analytics is measured.
 */
final readonly class BilledCostSchedule
{
    public const array READ_AT_MINUTES = [2, 5, 10, 20, 40];

    /** Seconds to wait before read number $readIndex, counted from the read before it, or from the run end for the first. */
    public static function delaySeconds(int $readIndex): int
    {
        $previous = $readIndex > 0 ? self::READ_AT_MINUTES[$readIndex - 1] : 0;

        return (self::READ_AT_MINUTES[$readIndex] - $previous) * 60;
    }

    public static function isLast(int $readIndex): bool
    {
        return $readIndex >= \count(self::READ_AT_MINUTES) - 1;
    }
}
