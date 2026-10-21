<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Workflow;

use App\Module\Inbox\Workflow\AgentReviewOpenFacts;
use App\Module\Inbox\Workflow\Condition\CardDocumentAgentReviewOpen;
use App\Module\Inbox\Workflow\Condition\CardRunAsking;
use App\Module\Inbox\Workflow\RunAskingFacts;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class RunAskingConditionTest extends TestCase
{
    private const string NOW = '2026-10-01 12:00:00';

    public function test_an_open_question_asks_with_no_end_to_the_wait(): void
    {
        $facts = $this->facts(new RunAskingFacts(true, null));

        self::assertTrue(new CardRunAsking()->evaluate($facts, []));
        self::assertNull(new CardRunAsking()->changesAt($facts, []));
    }

    public function test_a_question_that_closed_lately_asks_until_the_grace_ends(): void
    {
        $facts = $this->facts(new RunAskingFacts(false, new \DateTimeImmutable('2026-10-01 12:05:00')));

        self::assertTrue(new CardRunAsking()->evaluate($facts, []));
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:05:00'), new CardRunAsking()->changesAt($facts, []));
    }

    public function test_a_question_whose_grace_ended_does_not_ask(): void
    {
        $facts = $this->facts(new RunAskingFacts(false, new \DateTimeImmutable('2026-10-01 12:00:00')));

        self::assertFalse(new CardRunAsking()->evaluate($facts, []));
        self::assertNull(new CardRunAsking()->changesAt($facts, []));
        self::assertFalse(new CardRunAsking()->evaluate($this->facts(new RunAskingFacts(false, null)), []));
    }

    public function test_the_agent_review_condition_holds_while_a_document_is_under_review(): void
    {
        $open = FactsMother::facts(provided: [AgentReviewOpenFacts::class => new AgentReviewOpenFacts(['0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7'])]);

        self::assertTrue(new CardDocumentAgentReviewOpen()->evaluate($open, []));
        self::assertFalse(new CardDocumentAgentReviewOpen()->evaluate(FactsMother::facts(), []));
        self::assertTrue(new CardDocumentAgentReviewOpen()->evaluate($open->withDocument(new DocumentFacts([], 'in-review', '0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7')), []), 'the bound document is under review');
        self::assertFalse(new CardDocumentAgentReviewOpen()->evaluate($open->withDocument(new DocumentFacts([], 'in-review', 'other')), []), 'another document is under review');
    }

    public function test_the_waiting_sentences_follow_the_negation(): void
    {
        self::assertSame('workflow.waiting.card_run_asking', new CardRunAsking()->waitingFor([])->getMessage());
        self::assertSame('workflow.waiting.not.card_run_asking', new CardRunAsking()->waitingFor([], negated: true)->getMessage());
        self::assertSame('workflow.waiting.card_document_agent_review_open', new CardDocumentAgentReviewOpen()->waitingFor([])->getMessage());
        self::assertSame('workflow.waiting.not.card_document_agent_review_open', new CardDocumentAgentReviewOpen()->waitingFor([], negated: true)->getMessage());
    }

    private function facts(RunAskingFacts $asking): Facts
    {
        return FactsMother::facts(now: new \DateTimeImmutable(self::NOW), provided: [RunAskingFacts::class => $asking]);
    }
}
