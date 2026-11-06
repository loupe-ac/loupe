<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** Loupe can update the branch: the repository has an active installation, and the forge has not refused an update of this head for good. */
final readonly class PullRequestCanUpdateBranch implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.can_update_branch';
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
        return [EngineFact::PullRequest, EngineFact::PullRequestBaseUpdate, EngineFact::PullRequestApprovalCheck];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return true === $facts->pullRequest?->canPostCheck && false === $facts->pullRequest->updateRefusedOnHead;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_can_update_branch' : 'workflow.waiting.pr_can_update_branch');
    }
}
