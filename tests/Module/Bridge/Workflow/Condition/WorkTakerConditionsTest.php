<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Bridge\Workflow\Condition\RunWorkNoTaker;
use App\Module\Bridge\Workflow\Condition\RunWorkTakerPaused;
use App\Module\Bridge\Workflow\WorkTakerFacts;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class WorkTakerConditionsTest extends TestCase
{
    private const string NOW = '2026-10-16 12:00:00';

    public function test_no_open_request_means_neither_condition_holds(): void
    {
        $facts = self::facts([]);

        self::assertFalse(new RunWorkNoTaker()->evaluate($facts, []));
        self::assertFalse(new RunWorkTakerPaused()->evaluate($facts, []));
    }

    public function test_a_request_with_an_available_taker_holds_neither(): void
    {
        $facts = self::facts(['implement' => self::reading(WorkTakerState::Available)]);

        self::assertFalse(new RunWorkNoTaker()->evaluate($facts, []));
        self::assertFalse(new RunWorkTakerPaused()->evaluate($facts, []));
    }

    public function test_a_request_with_no_taker_holds_the_no_taker_condition_only(): void
    {
        $facts = self::facts(['implement' => self::reading(WorkTakerState::None)]);

        self::assertTrue(new RunWorkNoTaker()->evaluate($facts, []));
        self::assertFalse(new RunWorkTakerPaused()->evaluate($facts, []));
    }

    public function test_a_request_with_only_paused_takers_holds_the_paused_condition_only(): void
    {
        $facts = self::facts(['implement' => self::reading(WorkTakerState::Paused)]);

        self::assertFalse(new RunWorkNoTaker()->evaluate($facts, []));
        self::assertTrue(new RunWorkTakerPaused()->evaluate($facts, []));
    }

    public function test_a_request_with_no_taker_hides_the_paused_condition_of_another_request(): void
    {
        $facts = self::facts(['implement' => self::reading(WorkTakerState::Paused), 'fix' => self::reading(WorkTakerState::None)]);

        self::assertTrue(new RunWorkNoTaker()->evaluate($facts, []));
        self::assertFalse(new RunWorkTakerPaused()->evaluate($facts, []));
    }

    public function test_the_evidence_starts_at_the_longest_wait_without_a_taker(): void
    {
        $facts = self::facts([
            'implement' => self::reading(WorkTakerState::None, '2026-10-16 11:30:00'),
            'fix' => self::reading(WorkTakerState::None, '2026-10-16 11:00:00'),
        ]);

        self::assertEquals(new \DateTimeImmutable('2026-10-16 11:00:00'), new RunWorkNoTaker()->evidence($facts, [])?->since);
        self::assertNull(new RunWorkNoTaker()->evidence(self::facts([]), []));
    }

    public function test_the_conditions_change_when_the_first_online_taker_turns_quiet(): void
    {
        $facts = self::facts([
            'implement' => self::reading(WorkTakerState::Available, quietAt: '2026-10-16 12:05:00'),
            'fix' => self::reading(WorkTakerState::Paused, quietAt: '2026-10-16 12:02:00'),
        ]);

        self::assertEquals(new \DateTimeImmutable('2026-10-16 12:02:00'), new RunWorkNoTaker()->changesAt($facts, []));
        self::assertEquals(new \DateTimeImmutable('2026-10-16 12:02:00'), new RunWorkTakerPaused()->changesAt($facts, []));
    }

    public function test_the_conditions_have_no_change_time_while_nothing_can_go_quiet(): void
    {
        $facts = self::facts(['implement' => self::reading(WorkTakerState::None)]);

        self::assertNull(new RunWorkNoTaker()->changesAt($facts, []));
        self::assertNull(new RunWorkTakerPaused()->changesAt($facts, []));
    }

    public function test_the_conditions_name_their_facts_and_their_waiting_sentences(): void
    {
        foreach ([[new RunWorkNoTaker(), 'run_work_no_taker'], [new RunWorkTakerPaused(), 'run_work_taker_paused']] as [$condition, $suffix]) {
            self::assertSame([WorkTakerFacts::class], $condition->reads([]));
            self::assertSame('workflow.waiting.'.$suffix, $condition->waitingFor([])->getMessage());
            self::assertSame('workflow.waiting.not.'.$suffix, $condition->waitingFor([], negated: true)->getMessage());
        }
    }

    /** @param array<string, WorkTakerReading> $readings */
    private static function facts(array $readings): Facts
    {
        return FactsMother::facts(now: new \DateTimeImmutable(self::NOW), provided: [WorkTakerFacts::class => new WorkTakerFacts($readings)]);
    }

    private static function reading(WorkTakerState $state, string $since = '2026-10-16 11:00:00', ?string $quietAt = null): WorkTakerReading
    {
        return new WorkTakerReading($state, new \DateTimeImmutable($since), null === $quietAt ? null : new \DateTimeImmutable($quietAt));
    }
}
