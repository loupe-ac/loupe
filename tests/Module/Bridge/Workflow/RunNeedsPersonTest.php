<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow;

use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\Workflow\Condition\RunNeedsPerson;
use App\Module\Bridge\Workflow\RunNeedsPersonFacts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunNeedsPersonTest extends TestCase
{
    #[DataProvider('stopStates')]
    public function test_a_run_that_stopped_for_a_person_counts(WorkerRunState $state): void
    {
        self::assertTrue(new RunNeedsPersonFacts('run', $state, new \DateTimeImmutable('2026-10-01 10:00:00'), null)->needsPerson());
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function stopStates(): iterable
    {
        yield 'blocked' => [WorkerRunState::Blocked];
        yield 'gave up' => [WorkerRunState::GaveUp];
        yield 'waiting for a person' => [WorkerRunState::WaitingForPerson];
    }

    public function test_other_states_and_no_run_do_not_count(): void
    {
        self::assertFalse(new RunNeedsPersonFacts('run', WorkerRunState::Succeeded, null, null)->needsPerson());
        self::assertFalse(new RunNeedsPersonFacts('run', WorkerRunState::Running, null, null)->needsPerson());
        self::assertFalse(new RunNeedsPersonFacts('run', WorkerRunState::Failed, null, null)->needsPerson());
        self::assertFalse(new RunNeedsPersonFacts(null, null, null, null)->needsPerson());
    }

    public function test_a_move_of_the_card_after_the_run_ended_ends_the_need(): void
    {
        $ended = new \DateTimeImmutable('2026-10-01 10:00:00');

        self::assertFalse(new RunNeedsPersonFacts('run', WorkerRunState::Blocked, $ended, new \DateTimeImmutable('2026-10-01 10:00:00'))->needsPerson());
        self::assertFalse(new RunNeedsPersonFacts('run', WorkerRunState::Blocked, $ended, new \DateTimeImmutable('2026-10-01 11:00:00'))->needsPerson());
        self::assertTrue(new RunNeedsPersonFacts('run', WorkerRunState::Blocked, $ended, new \DateTimeImmutable('2026-10-01 09:00:00'))->needsPerson());
    }

    public function test_the_condition_reads_the_facts_and_names_its_waiting_sentence(): void
    {
        $needs = FactsMother::facts(provided: [RunNeedsPersonFacts::class => new RunNeedsPersonFacts('run', WorkerRunState::Blocked, null, null)]);

        self::assertTrue(new RunNeedsPerson()->evaluate($needs, []));
        self::assertFalse(new RunNeedsPerson()->evaluate(FactsMother::facts(), []));
        self::assertSame('workflow.waiting.card_run_needs_person', new RunNeedsPerson()->waitingFor([])->getMessage());
        self::assertSame('workflow.waiting.not.card_run_needs_person', new RunNeedsPerson()->waitingFor([], negated: true)->getMessage());
    }
}
