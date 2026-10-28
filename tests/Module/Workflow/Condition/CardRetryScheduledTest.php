<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\CardRetryScheduled;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Fact\ScheduledRetryFacts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class CardRetryScheduledTest extends TestCase
{
    public function test_a_retry_due_later_holds_until_its_due_time_and_shows_it(): void
    {
        $facts = self::facts('2026-10-02 12:00:00', ['2026-10-02 12:10:00', '2026-10-02 12:30:00']);

        self::assertTrue(new CardRetryScheduled()->evaluate($facts, []));
        self::assertEquals(new \DateTimeImmutable('2026-10-02 12:10:00'), new CardRetryScheduled()->changesAt($facts, []));
        self::assertEquals(new Evidence(['%due%' => '12:10']), new CardRetryScheduled()->evidence($facts, []));
    }

    public function test_a_retry_due_on_another_day_shows_the_date(): void
    {
        $facts = self::facts('2026-10-02 23:00:00', ['2026-10-03 02:00:00']);

        self::assertEquals(new Evidence(['%due%' => 'Oct 3, 02:00']), new CardRetryScheduled()->evidence($facts, []));
    }

    public function test_a_retry_whose_due_time_has_come_no_longer_holds(): void
    {
        $facts = self::facts('2026-10-02 12:10:00', ['2026-10-02 12:10:00']);

        self::assertFalse(new CardRetryScheduled()->evaluate($facts, []));
        self::assertNull(new CardRetryScheduled()->changesAt($facts, []));
        self::assertNull(new CardRetryScheduled()->evidence($facts, []));
    }

    public function test_a_past_due_time_does_not_hide_a_later_one(): void
    {
        $facts = self::facts('2026-10-02 12:00:00', ['2026-10-02 12:00:00', '2026-10-02 13:00:00']);

        self::assertTrue(new CardRetryScheduled()->evaluate($facts, []));
        self::assertEquals(new \DateTimeImmutable('2026-10-02 13:00:00'), new CardRetryScheduled()->changesAt($facts, []));
    }

    public function test_a_card_with_no_due_time_has_no_retry_scheduled(): void
    {
        $facts = self::facts('2026-10-02 12:00:00', []);

        self::assertFalse(new CardRetryScheduled()->evaluate($facts, []));
        self::assertNull(new CardRetryScheduled()->changesAt($facts, []));
    }

    /** @param list<string> $dueAts */
    private static function facts(string $now, array $dueAts): Facts
    {
        return FactsMother::facts(
            now: new \DateTimeImmutable($now),
            provided: [ScheduledRetryFacts::class => new ScheduledRetryFacts(array_map(static fn (string $at): \DateTimeImmutable => new \DateTimeImmutable($at), $dueAts))],
        );
    }
}
