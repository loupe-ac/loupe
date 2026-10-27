<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The ruleset of the default branch requires an approving review. */
final readonly class PullRequestBaseRequiresReview implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.base_requires_review';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.forge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [EngineFact::PullRequest, EngineFact::PullRequestRuleset];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return true === $facts->pullRequest?->baseRequiresReview;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_base_requires_review' : 'workflow.waiting.pr_base_requires_review');
    }
}
