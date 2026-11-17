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
        self::assertEquals(new \DateTimeImmutable('2026-11-16 12:05:00'), $facts->changesAt());
    }

    public function test_readings_that_never_change_by_the_clock_give_no_time(): void
    {
        self::assertNull(new WorkTakerFacts([])->changesAt());
        self::assertNull(new WorkTakerFacts(['work' => new WorkTakerReading(WorkTakerState::None, new \DateTimeImmutable('2026-11-16 11:00:00'), null)])->changesAt());
    }
}
