<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Workflow\AgentReviewPostingFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** A review of the card needs a summary comment on its pull request. */
final readonly class AgentReviewUncommented implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'agent_review.uncommented';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.agent_review';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [AgentReviewPostingFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $review = $facts->get(AgentReviewPostingFacts::class);

        return [] !== $review->uncommented;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.agent_review_uncommented' : 'workflow.waiting.agent_review_uncommented');
    }
}
