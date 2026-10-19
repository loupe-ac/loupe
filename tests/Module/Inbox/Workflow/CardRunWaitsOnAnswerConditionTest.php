<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Workflow;

use App\Module\Inbox\Workflow\Condition\CardRunWaitsOnAnswer;
use App\Module\Inbox\Workflow\WaitingAskFacts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class CardRunWaitsOnAnswerConditionTest extends TestCase
{
    public function test_it_holds_while_a_session_of_the_card_waits_for_an_answer(): void
    {
        $waiting = FactsMother::facts(provided: [WaitingAskFacts::class => new WaitingAskFacts(['0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7'])]);

        self::assertTrue(new CardRunWaitsOnAnswer()->evaluate($waiting, []));
        self::assertFalse(new CardRunWaitsOnAnswer()->evaluate(FactsMother::facts(), []));
    }

    public function test_the_waiting_sentence_follows_the_negation(): void
    {
        self::assertSame('workflow.waiting.card_run_waits_on_answer', new CardRunWaitsOnAnswer()->waitingFor([])->getMessage());
        self::assertSame('workflow.waiting.not.card_run_waits_on_answer', new CardRunWaitsOnAnswer()->waitingFor([], negated: true)->getMessage());
    }
}
