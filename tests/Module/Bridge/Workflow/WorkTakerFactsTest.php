<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow;

use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Bridge\Workflow\WorkTakerFacts;
use App\Module\Workflow\Contract\TimedFacts;
use PHPUnit\Framework\TestCase;

final class WorkTakerFactsTest extends TestCase
{
    public function test_the_facts_are_timed_and_give_the_earliest_time_of_their_readings(): void
    {
        $facts = new WorkTakerFacts([
            'work' => new WorkTakerReading(WorkTakerState::Available, new \DateTimeImmutable('2026-11-16 11:00:00'), new \DateTimeImmutable('2026-11-16 12:10:01')),
            'fix' => new WorkTakerReading(WorkTakerState::Limited, new \DateTimeImmutable('2026-11-16 11:00:00'), new \DateTimeImmutable('2026-11-16 12:05:00')),
            'review' => new WorkTakerReading(WorkTakerState::None, new \DateTimeImmutable('2026-11-16 11:00:00'), null),
        ]);

        self::assertInstanceOf(TimedFacts::class, $facts);
        self::assertEquals(new \DateTimeImmutable('2026-11-16 12:05:00'), $facts->changesAt(new \DateTimeImmutable('2026-11-16 12:00:00')));
    }

    public function test_readings_that_never_change_by_the_clock_give_no_time(): void
    {
        self::assertNull(new WorkTakerFacts([])->changesAt(new \DateTimeImmutable('2026-11-16 12:00:00')));
        self::assertNull(new WorkTakerFacts(['work' => new WorkTakerReading(WorkTakerState::None, new \DateTimeImmutable('2026-11-16 11:00:00'), null)])->changesAt(new \DateTimeImmutable('2026-11-16 12:00:00')));
    }

    public function test_a_time_in_the_past_never_hides_a_later_time_of_another_request(): void
    {
        $facts = new WorkTakerFacts([
            'work' => new WorkTakerReading(WorkTakerState::Disconnected, new \DateTimeImmutable('2026-11-16 11:00:00'), new \DateTimeImmutable('2026-11-16 11:30:00')),
            'fix' => new WorkTakerReading(WorkTakerState::Limited, new \DateTimeImmutable('2026-11-16 11:00:00'), new \DateTimeImmutable('2026-11-16 13:00:00')),
        ]);

        self::assertEquals(new \DateTimeImmutable('2026-11-16 13:00:00'), $facts->changesAt(new \DateTimeImmutable('2026-11-16 12:00:00')));
        self::assertNull($facts->changesAt(new \DateTimeImmutable('2026-11-16 13:00:00')));
    }
}
