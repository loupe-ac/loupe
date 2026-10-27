<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The newest agent review of the head of the pull request the rule acts on failed, no fix round has answered it yet, and no approval overrides it. */
final readonly class AgentReviewFailed implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'agent_review.failed';
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
        return [AgentReviewFacts::class, EngineFact::PullRequestSubject];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $review = $facts->get(AgentReviewFacts::class);

        $head = $review->boundHead($facts);

        return AgentReviewConclusion::Failure === $head?->conclusion && !$head->answered && null === $head->overriddenBy;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.agent_review_failed' : 'workflow.waiting.agent_review_failed');
    }
}
