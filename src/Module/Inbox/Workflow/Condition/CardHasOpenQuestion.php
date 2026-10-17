<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow\Condition;

use App\Module\Inbox\Workflow\OpenQuestion;
use App\Module\Inbox\Workflow\OpenQuestionFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/** An open inbox item asks a person something about the card. With no `blocking`, any open ask matches. */
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
        return [new Parameter('blocking', ParameterType::Bool, required: false)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [OpenQuestionFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== self::question($facts, $params);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        $suffix = match (ParameterValue::optionalBool($params, 'blocking')) {
            true => 'card_inbox_open_question_blocking',
            false => 'card_inbox_open_question_non_blocking',
            null => 'card_inbox_open_question',
        };

        return new TranslatableMessage(($negated ? 'workflow.waiting.not.' : 'workflow.waiting.').$suffix);
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $question = self::question($facts, $params);

        return null === $question ? null : new Evidence($question->since, ['%number%' => $question->number, '%title%' => $question->title]);
    }

    /** @param array<string, mixed> $params */
    private static function question(Facts $facts, array $params): ?OpenQuestion
    {
        return $facts->get(OpenQuestionFacts::class)->oldest(ParameterValue::optionalBool($params, 'blocking'));
    }
}
