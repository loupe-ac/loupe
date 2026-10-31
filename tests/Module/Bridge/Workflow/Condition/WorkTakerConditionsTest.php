<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\ValueObject\AccountLimit;
use App\Module\Bridge\ValueObject\AccountReadingReport;
use App\Module\Bridge\ValueObject\UsageLimitWait;
use App\Module\Bridge\ValueObject\WindowLimit;
use App\Module\Bridge\ValueObject\WindowLimitState;
use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Bridge\Workflow\Condition\RunWorkNoCapableTaker;
use App\Module\Bridge\Workflow\Condition\RunWorkNoTaker;
use App\Module\Bridge\Workflow\Condition\RunWorkTakerLimited;
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

    public function test_a_request_with_an_online_bridge_that_cannot_run_it_holds_both_no_taker_conditions(): void
    {
        $facts = self::facts(['implement' => self::reading(WorkTakerState::NotCapable)]);

        self::assertTrue(new RunWorkNoCapableTaker()->evaluate($facts, []));
        self::assertTrue(new RunWorkNoTaker()->evaluate($facts, []));
        self::assertFalse(new RunWorkTakerPaused()->evaluate($facts, []));
        self::assertFalse(new RunWorkNoCapableTaker()->evaluate(self::facts(['implement' => self::reading(WorkTakerState::None)]), []));
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

    public function test_a_limited_request_holds_the_limited_condition_only(): void
    {
        $facts = self::facts(['implement' => self::limitedReading([self::account('claude', 'week', 100.0, '2026-11-01 17:00:00', true)])]);

        self::assertTrue(new RunWorkTakerLimited()->evaluate($facts, []));
        self::assertFalse(new RunWorkNoTaker()->evaluate($facts, []));
        self::assertFalse(new RunWorkTakerPaused()->evaluate($facts, []));
        self::assertFalse(new RunWorkTakerLimited()->evaluate(self::facts(['implement' => self::reading(WorkTakerState::Available)]), []));
    }

    public function test_a_request_with_no_taker_hides_the_limited_condition_of_another_request(): void
    {
        $facts = self::facts([
            'implement' => self::limitedReading([self::account('claude', 'week', 100.0, '2026-11-01 17:00:00', true)]),
            'fix' => self::reading(WorkTakerState::None),
        ]);

        self::assertFalse(new RunWorkTakerLimited()->evaluate($facts, []));
    }

    public function test_the_limited_evidence_of_a_hard_limit_names_the_window_that_releases_the_work_first(): void
    {
        $facts = self::facts(['implement' => self::limitedReading([
            self::account('spare', 'week', 100.0, '2026-11-03 09:00:00', true),
            self::account('claude', 'week', 100.0, '2026-11-01 17:00:00', true),
        ])]);

        $evidence = new RunWorkTakerLimited()->evidence($facts, []);

        self::assertSame(['%cause%' => 'limit', '%account%' => 'claude', '%window%' => 'week', '%use%' => 100, '%stopAt%' => 95, '%resetsAt%' => new \DateTimeImmutable('2026-11-01 17:00:00')->getTimestamp()], $evidence?->params);
    }

    public function test_the_limited_evidence_of_a_stop_value_carries_the_use_and_the_stop_value(): void
    {
        $facts = self::facts(['implement' => self::limitedReading([self::account('claude', 'week', 96.2, '2026-11-01 17:00:00', false)])]);

        $params = new RunWorkTakerLimited()->evidence($facts, [])?->params;

        self::assertSame('stop', $params['%cause%'] ?? null);
        self::assertSame(96, $params['%use%'] ?? null);
        self::assertSame(95, $params['%stopAt%'] ?? null);
    }

    public function test_the_limited_evidence_leaves_the_use_empty_when_the_reading_has_none(): void
    {
        $facts = self::facts(['implement' => self::limitedReading([self::account('claude', 'session', null, '2026-11-01 17:00:00', true)])]);

        self::assertSame('', new RunWorkTakerLimited()->evidence($facts, [])?->params['%use%'] ?? null);
        self::assertNull(new RunWorkTakerLimited()->evidence(self::facts([]), []));
    }

    public function test_the_limited_condition_changes_at_the_first_reset(): void
    {
        $reading = self::limitedReading([self::account('claude', 'week', 100.0, '2026-11-01 17:00:00', true)], quietAt: '2026-11-01 17:00:00');

        self::assertEquals(new \DateTimeImmutable('2026-11-01 17:00:00'), new RunWorkTakerLimited()->changesAt(self::facts(['implement' => $reading]), []));
    }

    public function test_the_conditions_name_their_facts_and_their_waiting_sentences(): void
    {
        foreach ([[new RunWorkNoTaker(), 'run_work_no_taker'], [new RunWorkNoCapableTaker(), 'run_work_no_capable_taker'], [new RunWorkTakerPaused(), 'run_work_taker_paused'], [new RunWorkTakerLimited(), 'run_work_taker_limited']] as [$condition, $suffix]) {
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

    private static function account(string $name, string $window, ?float $usedPercent, string $resetsAt, bool $limited): AccountLimit
    {
        $reading = new AccountReadingReport($name, $window, $usedPercent, new \DateTimeImmutable($resetsAt), $limited, 'stream', new \DateTimeImmutable('2026-10-16 11:00:00'));

        return new AccountLimit($name, [new WindowLimit($reading, $limited ? WindowLimitState::AtLimit : WindowLimitState::AtStop)], 95);
    }

    /** @param non-empty-list<AccountLimit> $accounts */
    private static function limitedReading(array $accounts, ?string $quietAt = null): WorkTakerReading
    {
        return new WorkTakerReading(WorkTakerState::Limited, new \DateTimeImmutable('2026-10-16 11:00:00'), null === $quietAt ? null : new \DateTimeImmutable($quietAt), limit: new UsageLimitWait($accounts));
    }

    private static function reading(WorkTakerState $state, string $since = '2026-10-16 11:00:00', ?string $quietAt = null): WorkTakerReading
    {
        return new WorkTakerReading($state, new \DateTimeImmutable($since), null === $quietAt ? null : new \DateTimeImmutable($quietAt));
    }
}
