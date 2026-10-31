<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Bridge\Workflow\Condition\CardHeld;
use App\Module\Bridge\Workflow\Condition\RunSessionOpen;
use App\Module\Bridge\Workflow\Condition\RunStopped;
use App\Module\Bridge\Workflow\Condition\RunWorkActive;
use App\Module\Bridge\Workflow\Condition\RunWorkerActive;
use App\Module\Bridge\Workflow\HoldFacts;
use App\Module\Bridge\Workflow\RunStoppedFacts;
use App\Module\Bridge\Workflow\SessionFacts;
use App\Module\Bridge\Workflow\WorkerRunFacts;
use App\Module\Bridge\Workflow\WorkRequestFacts;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class RunMarkerConditionsTest extends TestCase
{
    public function test_a_run_that_gave_up_stops_the_card_until_the_card_moves(): void
    {
        $endedAt = new \DateTimeImmutable('2026-10-01 10:00:00');
        $warning = new CardRunWarning('run', WorkerRunState::GaveUp, 'summary', $endedAt);
        $condition = new RunStopped();

        $unmoved = self::facts(new RunStoppedFacts($warning, null));
        self::assertTrue($condition->evaluate($unmoved, []));
        self::assertEquals(new Evidence(['%state%' => 'gave-up']), $condition->evidence($unmoved, []));

        self::assertTrue($condition->evaluate(self::facts(new RunStoppedFacts($warning, new \DateTimeImmutable('2026-10-01 09:59:59'))), []));

        $moved = self::facts(new RunStoppedFacts($warning, $endedAt));
        self::assertFalse($condition->evaluate($moved, []));
        self::assertNull($condition->evidence($moved, []));

        self::assertFalse($condition->evaluate(self::facts(new RunStoppedFacts(null, null)), []));
    }

    public function test_an_open_session_shows_its_name(): void
    {
        $since = new \DateTimeImmutable('2026-10-01 09:00:00');
        $condition = new RunSessionOpen();

        $named = self::facts(new SessionFacts($since, 'review'));
        self::assertTrue($condition->evaluate($named, []));
        self::assertEquals(new Evidence(['%kind%' => 'review']), $condition->evidence($named, []));

        self::assertEquals(new Evidence(), $condition->evidence(self::facts(new SessionFacts($since, null)), []));

        $closed = self::facts(new SessionFacts(null, null));
        self::assertFalse($condition->evaluate($closed, []));
        self::assertNull($condition->evidence($closed, []));
    }

    public function test_a_card_is_held_while_a_person_holds_it(): void
    {
        $condition = new CardHeld();

        self::assertTrue($condition->evaluate(self::facts(new HoldFacts(new \DateTimeImmutable('2026-10-01 09:00:00'))), []));
        self::assertFalse($condition->evaluate(self::facts(new HoldFacts(null)), []));
    }

    public function test_an_open_worker_run_shows_the_oldest_kind_or_the_asked_kind(): void
    {
        $since = [
            'fix' => new \DateTimeImmutable('2026-10-01 10:00:00'),
            'implement' => new \DateTimeImmutable('2026-10-01 09:00:00'),
        ];
        $facts = self::facts(new WorkerRunFacts(['fix', 'implement'], $since, ['fix'], ['fix' => $since['fix']]));
        $condition = new RunWorkerActive();

        self::assertEquals(new Evidence(['%kind%' => 'implement']), $condition->evidence($facts, []));
        self::assertEquals(new Evidence(['%kind%' => 'fix']), $condition->evidence($facts, ['kind' => 'fix']));
        self::assertNull($condition->evidence($facts, ['kind' => 'review']));
        self::assertEquals(new Evidence(['%kind%' => 'fix']), $condition->evidence($facts, ['origin' => 'rule']), 'A run that a person started is no evidence for a rule-only reading.');
        self::assertNull($condition->evidence($facts, ['origin' => 'rule', 'kind' => 'implement']));
        self::assertNull($condition->evidence(self::facts(new WorkerRunFacts([], [], [], [])), []));
    }

    public function test_a_live_work_request_shows_the_oldest_kind_or_the_asked_kind(): void
    {
        $since = [
            'review' => new \DateTimeImmutable('2026-10-01 08:00:00'),
            'fix' => new \DateTimeImmutable('2026-10-01 10:00:00'),
        ];
        $facts = self::facts(new WorkRequestFacts(['review', 'fix'], $since, ['fix'], ['fix' => $since['fix']]));
        $condition = new RunWorkActive();

        self::assertEquals(new Evidence(['%kind%' => 'review']), $condition->evidence($facts, []));
        self::assertEquals(new Evidence(['%kind%' => 'fix']), $condition->evidence($facts, ['kind' => 'fix']));
        self::assertNull($condition->evidence($facts, ['kind' => 'merge']));
        self::assertEquals(new Evidence(['%kind%' => 'fix']), $condition->evidence($facts, ['origin' => 'rule']), 'A request that a person started is no evidence for a rule-only reading.');
        self::assertNull($condition->evidence($facts, ['origin' => 'rule', 'kind' => 'review']));
        self::assertNull($condition->evidence(self::facts(new WorkRequestFacts([], [], [], [])), []));
    }

    private static function facts(object $provided): Facts
    {
        return FactsMother::facts(provided: [$provided::class => $provided]);
    }
}
