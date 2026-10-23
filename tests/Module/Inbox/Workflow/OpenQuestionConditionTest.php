<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Workflow;

use App\Module\Inbox\Workflow\Condition\CardHasOpenQuestion;
use App\Module\Inbox\Workflow\OpenQuestion;
use App\Module\Inbox\Workflow\OpenQuestionFacts;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class OpenQuestionConditionTest extends TestCase
{
    public function test_an_open_question_shows_its_number_and_title_since_it_was_asked(): void
    {
        $since = new \DateTimeImmutable('2026-10-02 09:15:00');
        $facts = self::facts(new OpenQuestion(4, 'Which option?', $since, blocking: true));

        self::assertTrue(new CardHasOpenQuestion()->evaluate($facts, []));
        self::assertEquals(new Evidence(['%number%' => 4, '%title%' => 'Which option?']), new CardHasOpenQuestion()->evidence($facts, []));
    }

    public function test_a_card_with_no_open_question_needs_nobody(): void
    {
        $facts = self::facts();

        foreach ([[], ['blocking' => true], ['blocking' => false]] as $params) {
            self::assertFalse(new CardHasOpenQuestion()->evaluate($facts, $params));
            self::assertNull(new CardHasOpenQuestion()->evidence($facts, $params));
        }
    }

    public function test_with_no_blocking_parameter_a_non_blocking_to_do_matches(): void
    {
        $facts = self::facts(new OpenQuestion(929, 'Rebuild the Design System artifact', new \DateTimeImmutable('2026-10-02 09:15:00'), blocking: false));

        self::assertTrue(new CardHasOpenQuestion()->evaluate($facts, []));
    }

    public function test_a_blocking_condition_skips_a_non_blocking_to_do_alone(): void
    {
        $facts = self::facts(new OpenQuestion(929, 'Rebuild the Design System artifact', new \DateTimeImmutable('2026-10-02 09:15:00'), blocking: false));

        self::assertFalse(new CardHasOpenQuestion()->evaluate($facts, ['blocking' => true]));
        self::assertNull(new CardHasOpenQuestion()->evidence($facts, ['blocking' => true]));
    }

    public function test_a_non_blocking_condition_skips_a_blocking_question_alone(): void
    {
        $facts = self::facts(new OpenQuestion(4, 'Which option?', new \DateTimeImmutable('2026-10-02 09:15:00'), blocking: true));

        self::assertFalse(new CardHasOpenQuestion()->evaluate($facts, ['blocking' => false]));
    }

    public function test_the_evidence_names_the_oldest_ask_that_matches_the_blocking_parameter(): void
    {
        $todoSince = new \DateTimeImmutable('2026-10-02 09:00:00');
        $questionSince = new \DateTimeImmutable('2026-10-02 09:30:00');
        $facts = self::facts(
            new OpenQuestion(929, 'Rebuild the Design System artifact', $todoSince, blocking: false),
            new OpenQuestion(931, 'Which option?', $questionSince, blocking: true),
        );
        $todo = new Evidence(['%number%' => 929, '%title%' => 'Rebuild the Design System artifact']);

        self::assertTrue(new CardHasOpenQuestion()->evaluate($facts, ['blocking' => true]));
        self::assertEquals(new Evidence(['%number%' => 931, '%title%' => 'Which option?']), new CardHasOpenQuestion()->evidence($facts, ['blocking' => true]));
        self::assertEquals($todo, new CardHasOpenQuestion()->evidence($facts, ['blocking' => false]));
        self::assertEquals($todo, new CardHasOpenQuestion()->evidence($facts, []));
    }

    public function test_the_waiting_sentence_follows_the_blocking_parameter(): void
    {
        $condition = new CardHasOpenQuestion();

        self::assertSame('workflow.waiting.card_inbox_open_question', $condition->waitingFor([])->getMessage());
        self::assertSame('workflow.waiting.not.card_inbox_open_question_blocking', $condition->waitingFor(['blocking' => true], negated: true)->getMessage());
        self::assertSame('workflow.waiting.card_inbox_open_question_non_blocking', $condition->waitingFor(['blocking' => false])->getMessage());
    }

    private static function facts(OpenQuestion ...$questions): Facts
    {
        return FactsMother::facts(provided: [OpenQuestionFacts::class => new OpenQuestionFacts(array_values($questions))]);
    }
}
