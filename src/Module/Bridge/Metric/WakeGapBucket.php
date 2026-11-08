<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/** The gap between the last turn of a session and its resume. Both edges, 5 and 60 minutes, fall in the middle bucket. */
enum WakeGapBucket
{
    case UnderFiveMinutes;
    case FiveMinutesToOneHour;
    case OverOneHour;

    private const int FIVE_MINUTES_MS = 300_000;

    private const int ONE_HOUR_MS = 3_600_000;

    public static function fromMilliseconds(int $gapMs): self
    {
        return match (true) {
            $gapMs < self::FIVE_MINUTES_MS => self::UnderFiveMinutes,
            $gapMs <= self::ONE_HOUR_MS => self::FiveMinutesToOneHour,
            default => self::OverOneHour,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $bucket): string => $bucket->key(), self::cases());
    }

    /**
     * The value of the group that crosses the gap with the cache, shortest gap first and warm before cold.
     *
     * @return list<string>
     */
    public static function cacheValues(): array
    {
        $values = [];
        foreach (self::cases() as $bucket) {
            $values[] = $bucket->cacheValue(false);
            $values[] = $bucket->cacheValue(true);
        }

        return $values;
    }

    /** The value of the group, which agents and URLs read. */
    public function key(): string
    {
        return match ($this) {
            self::UnderFiveMinutes => 'under-5m',
            self::FiveMinutesToOneHour => '5m-to-1h',
            self::OverOneHour => 'over-1h',
        };
    }

    public function cacheValue(bool $cold): string
    {
        return $this->key().($cold ? '-cold' : '-warm');
    }
}
