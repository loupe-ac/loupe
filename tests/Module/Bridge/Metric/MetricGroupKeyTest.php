<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Metric\MetricGroupKey;
use PHPUnit\Framework\TestCase;

final class MetricGroupKeyTest extends TestCase
{
    public function test_keys_with_the_same_parts_share_an_id(): void
    {
        self::assertSame(
            new MetricGroupKey('b1', 'work', 'session')->id(),
            new MetricGroupKey('b1', 'work', 'session')->id(),
        );
    }

    public function test_keys_with_different_parts_differ(): void
    {
        $ids = [
            new MetricGroupKey(account: 'work')->id(),
            new MetricGroupKey(bridgeId: 'work')->id(),
            new MetricGroupKey('b1', 'work', 'session')->id(),
            new MetricGroupKey('b1', 'work', 'week')->id(),
        ];

        self::assertCount(4, array_unique($ids));
    }

    public function test_a_separator_inside_a_part_cannot_make_two_keys_collide(): void
    {
        self::assertNotSame(
            new MetricGroupKey('b1', 'a|b', 'c')->id(),
            new MetricGroupKey('b1', 'a', 'b|c')->id(),
        );
        self::assertNotSame(
            new MetricGroupKey(account: 'a')->id(),
            new MetricGroupKey(account: 'a', window: '')->id(),
        );
    }
}
