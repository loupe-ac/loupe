<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\CardHeldByWorkHold;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Fact\WorkHoldFacts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class CardHeldByWorkHoldTest extends TestCase
{
    public function test_a_card_with_a_rule_that_waits_for_the_work_hold_is_held(): void
    {
        self::assertTrue(new CardHeldByWorkHold()->evaluate(self::facts(new \DateTimeImmutable('2026-10-01 09:10:00')), []));
    }

    public function test_a_card_with_no_such_rule_is_not_held(): void
    {
        self::assertFalse(new CardHeldByWorkHold()->evaluate(self::facts(null), []));
    }

    public function test_it_reads_the_work_hold_facts_and_names_its_waiting_sentences(): void
    {
        $condition = new CardHeldByWorkHold();

        self::assertSame([WorkHoldFacts::class], $condition->reads([]));
        self::assertSame('workflow.waiting.card_rule_held_by_work_hold', $condition->waitingFor([])->getMessage());
        self::assertSame('workflow.waiting.not.card_rule_held_by_work_hold', $condition->waitingFor([], true)->getMessage());
    }

    private static function facts(?\DateTimeImmutable $since): Facts
    {
        return FactsMother::facts(provided: [WorkHoldFacts::class => new WorkHoldFacts($since)]);
    }
}
