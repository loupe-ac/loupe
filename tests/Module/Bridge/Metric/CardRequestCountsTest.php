<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Metric\CardRequestCounts;
use App\Module\Workflow\Contract\CountFilter;
use PHPUnit\Framework\TestCase;

final class CardRequestCountsTest extends TestCase
{
    public function test_a_filter_field_picks_the_requests_and_an_empty_field_picks_all(): void
    {
        $counts = CardRequestCounts::fromRows([
            ['cardId' => 'a', 'role' => 'fix', 'kind' => 'fix', 'reason' => 'conflict', 'count' => 2],
            ['cardId' => 'a', 'role' => 'fix', 'kind' => 'fix', 'reason' => 'checks-failed', 'count' => 1],
            ['cardId' => 'a', 'role' => 'code-review', 'kind' => 'review', 'reason' => 'unknown', 'count' => 4],
            ['cardId' => 'a', 'role' => null, 'kind' => 'implement', 'reason' => 'unknown', 'count' => 1],
            ['cardId' => 'b', 'role' => 'fix', 'kind' => 'fix', 'reason' => 'conflict', 'count' => 5],
        ]);

        self::assertSame(3, $counts->matching('a', new CountFilter(role: 'fix')));
        self::assertSame(2, $counts->matching('a', new CountFilter(role: 'fix', reason: 'conflict')));
        self::assertSame(4, $counts->matching('a', new CountFilter(kind: 'review')));
        self::assertSame(8, $counts->matching('a', new CountFilter()));
        self::assertSame(0, $counts->matching('a', new CountFilter(role: 'fix', kind: 'review')));
        self::assertSame(0, $counts->matching('missing', new CountFilter()));
    }

    public function test_the_split_by_reason_reads_the_requests_that_the_filter_picks(): void
    {
        $counts = CardRequestCounts::fromRows([
            ['cardId' => 'a', 'role' => 'fix', 'kind' => 'fix', 'reason' => 'conflict', 'count' => 2],
            ['cardId' => 'a', 'role' => 'fix', 'kind' => 'implement', 'reason' => 'conflict', 'count' => 1],
            ['cardId' => 'a', 'role' => 'fix', 'kind' => 'fix', 'reason' => 'checks-failed', 'count' => 1],
            ['cardId' => 'a', 'role' => 'code-review', 'kind' => 'review', 'reason' => 'unknown', 'count' => 4],
        ]);

        self::assertSame(['checks-failed' => 1, 'conflict' => 3], $counts->byReason('a', new CountFilter(role: 'fix')));
        self::assertSame(['conflict' => 3], $counts->byReason('a', new CountFilter(role: 'fix', reason: 'conflict')));
        self::assertSame([], $counts->byReason('missing', new CountFilter()));
    }
}
