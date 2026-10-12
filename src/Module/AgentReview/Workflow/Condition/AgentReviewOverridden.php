<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\AgentReview\Workflow\ReviewedHead;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** Every open pull request head has an agent review that passed or that an approval overrides, and an approval overrides at least one. */
final readonly class AgentReviewOverridden implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'agent_review.overridden';
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
        return [AgentReviewFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $review = $facts->get(AgentReviewFacts::class);

        return !$review->epic
            && array_any($review->heads, static fn (ReviewedHead $head): bool => null !== $head->overriddenBy)
            && array_all($review->heads, static fn (ReviewedHead $head): bool => AgentReviewConclusion::Success === $head->conclusion || null !== $head->overriddenBy);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.agent_review_overridden' : 'workflow.waiting.agent_review_overridden');
    }
}
