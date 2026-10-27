<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The head of the pull request the rule acts on has no agent review, or its failed review has been answered by a fix round that ended done.
 * An approval that overrides a failed review makes the head not due.
 */
final readonly class AgentReviewDue implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'agent_review.due';
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
        return [AgentReviewFacts::class, EngineFact::PullRequest];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $review = $facts->get(AgentReviewFacts::class);

        $head = $review->boundHead($facts);

        return null !== $head && '' !== $head->headSha && null === $head->overriddenBy && (null === $head->conclusion || $head->answered);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.agent_review_due' : 'workflow.waiting.agent_review_due');
    }
}
