<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow\Condition;

use App\Module\Inbox\Workflow\OpenQuestionFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use Symfony\Component\Translation\TranslatableMessage;

/** An open inbox item asks a person something about the card. */
final readonly class CardHasOpenQuestion implements Condition, MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'card.inbox.open_question';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.inbox';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [OpenQuestionFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== $facts->get(OpenQuestionFacts::class)->question;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_inbox_open_question' : 'workflow.waiting.card_inbox_open_question');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $question = $facts->get(OpenQuestionFacts::class)->question;

        return null === $question ? null : new Evidence($question->since, ['%number%' => $question->number, '%title%' => $question->title]);
    }
}
