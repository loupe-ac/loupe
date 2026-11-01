<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricRange;
use PHPUnit\Framework\TestCase;

final class MetricRangeTest extends TestCase
{
    public function test_seven_days_start_a_week_back_and_take_the_bucket_day_alone(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 12:00:00');

        self::assertEquals(new \DateTimeImmutable('2026-09-28 12:00:00'), MetricRange::SevenDays->startFrom($now));
        self::assertSame([MetricBucket::Day], MetricRange::SevenDays->allowedBuckets());
        self::assertSame(MetricBucket::cases(), MetricRange::ThirtyDays->allowedBuckets());
    }

    public function test_each_range_but_all_has_a_length_that_matches_its_start(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 12:00:00');

        foreach ([MetricRange::SevenDays, MetricRange::ThirtyDays, MetricRange::NinetyDays] as $range) {
            $length = $range->length();
            self::assertNotNull($length);
            self::assertEquals($range->startFrom($now), $now->sub($length));
        }
        self::assertNull(MetricRange::All->length());
        self::assertNull(MetricRange::All->startFrom($now));
    }
}
