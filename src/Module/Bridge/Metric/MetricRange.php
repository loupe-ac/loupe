<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

enum MetricRange: string
{
    case SevenDays = 'seven-days';
    case ThirtyDays = 'thirty-days';
    case NinetyDays = 'ninety-days';
    case All = 'all';

    /** Null for all time. */
    public function startFrom(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return match ($this) {
            self::SevenDays => $now->modify('-7 days'),
            self::ThirtyDays => $now->modify('-30 days'),
            self::NinetyDays => $now->modify('-90 days'),
            self::All => null,
        };
    }

    /** The length of the window, null for all time. */
    public function length(): ?\DateInterval
    {
        return match ($this) {
            self::SevenDays => new \DateInterval('P7D'),
            self::ThirtyDays => new \DateInterval('P30D'),
            self::NinetyDays => new \DateInterval('P90D'),
            self::All => null,
        };
    }

    /**
     * Seven days hold too few weeks and months to draw, so that window takes days alone.
     *
     * @return list<MetricBucket>
     */
    public function allowedBuckets(): array
    {
        return self::SevenDays === $this ? [MetricBucket::Day] : MetricBucket::cases();
    }
}
