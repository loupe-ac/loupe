<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The merge of the pull request waits for a person: the repository answered person to the merge question, or it has no answer yet. */
final readonly class PullRequestMergeApprovalNeedsPerson implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.merge_approval_needs_person';
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
        return [EngineFact::PullRequest, EngineFact::PullRequestApprovalCheck];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return true === $facts->pullRequest?->mergeApprovalNeedsPerson;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_merge_approval_needs_person' : 'workflow.waiting.pr_merge_approval_needs_person');
    }
}
