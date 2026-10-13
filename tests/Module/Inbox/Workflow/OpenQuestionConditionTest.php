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
        $since = new \DateTimeImmutable('2026-10-01 09:15:00');
        $facts = self::facts(new OpenQuestion(4, 'Which option?', $since));

        self::assertTrue(new CardHasOpenQuestion()->evaluate($facts, []));
        self::assertEquals(new Evidence($since, ['%number%' => 4, '%title%' => 'Which option?']), new CardHasOpenQuestion()->evidence($facts, []));
    }

    public function test_a_card_with_no_open_question_needs_nobody(): void
    {
        $facts = self::facts(null);

        self::assertFalse(new CardHasOpenQuestion()->evaluate($facts, []));
        self::assertNull(new CardHasOpenQuestion()->evidence($facts, []));
    }

    private static function facts(?OpenQuestion $question): Facts
    {
        return FactsMother::facts(provided: [OpenQuestionFacts::class => new OpenQuestionFacts($question)]);
    }
}
