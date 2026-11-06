<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Metric\MetricGroupKey;
use App\Module\Bridge\Metric\MetricRow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class MetricRowTest extends TestCase
{
    public function test_moved_by_shifts_the_time_and_keeps_every_other_field(): void
    {
        $row = new MetricRow(Uuid::v7(), 7, 'work', new \DateTimeImmutable('2026-08-11 09:00:00'), 2.5, new MetricGroupKey(account: 'work'));

        $moved = $row->movedBy(new \DateInterval('P30D'));

        self::assertEquals(new \DateTimeImmutable('2026-09-10 09:00:00'), $moved->time);
        self::assertSame($row->unitId, $moved->unitId);
        self::assertSame(7, $moved->cardNumber);
        self::assertSame('work', $moved->group);
        self::assertSame(2.5, $moved->value);
        self::assertEquals(new MetricGroupKey(account: 'work'), $moved->groupKey);
    }
}
