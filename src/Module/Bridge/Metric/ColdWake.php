<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Entity\WorkerRunFact;

/** A wake is cold when the cache write of its first turn is more than half of the context of that turn. */
final class ColdWake
{
    /** Null when the first-turn figures are unknown. */
    public static function of(WorkerRunFact $fact): ?bool
    {
        if (null === $fact->wakeTrigger || null === $fact->wakeFirstInputTokens || null === $fact->wakeFirstCacheReadTokens || null === $fact->wakeFirstCacheWriteTokens) {
            return null;
        }

        return $fact->wakeFirstCacheWriteTokens > $fact->wakeFirstInputTokens + $fact->wakeFirstCacheReadTokens;
    }

    /** The gap bucket of a wake, or null when the run is no wake or has no gap. */
    public static function gapBucket(WorkerRunFact $fact): ?WakeGapBucket
    {
        if (null === $fact->wakeTrigger || null === $fact->wakeGapMs) {
            return null;
        }

        return WakeGapBucket::fromMilliseconds($fact->wakeGapMs);
    }

    public static function cacheGroup(WorkerRunFact $fact): ?string
    {
        $cold = self::of($fact);
        $bucket = self::gapBucket($fact);

        return null === $cold || null === $bucket ? null : $bucket->cacheValue($cold);
    }
}
