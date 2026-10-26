<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\HumanReviewFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** True when a person must review the pull request of the card: its switch is on, or, for an epic, a child needs a person. */
final readonly class CardReviewNeedsPerson implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.review.needs_person';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [HumanReviewFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(HumanReviewFacts::class)->needsPerson;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_review_needs_person' : 'workflow.waiting.card_review_needs_person');
    }
}
