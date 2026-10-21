<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\PullRequestState;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestChecksPending implements Condition, MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.checks_pending';
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
        $pullRequest = $facts->pullRequest;

        return null !== $pullRequest && PullRequestState::Open === $pullRequest->state && ChecksState::Pending === $pullRequest->checks;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_checks_pending' : 'workflow.waiting.pr_checks_pending');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $pullRequest = $facts->pullRequest;

        return null === $pullRequest ? null : PullRequestEvidence::of($pullRequest);
    }
}
