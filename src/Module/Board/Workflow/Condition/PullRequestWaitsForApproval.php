<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use Symfony\Component\Translation\TranslatableMessage;

/** The pull request is green and into the default branch, and no approval covers its head. */
final readonly class PullRequestWaitsForApproval implements Condition, MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.waits_for_approval';
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
        return [EngineFact::PullRequest];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return true === $facts->pullRequest?->waitsForApproval;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_waits_for_approval' : 'workflow.waiting.pr_waits_for_approval');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $pullRequest = $facts->pullRequest;

        return null === $pullRequest ? null : PullRequestEvidence::of($pullRequest, $pullRequest->waitsForApprovalSince);
    }
}
