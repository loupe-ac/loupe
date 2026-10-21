<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\TimedCondition;
use Symfony\Component\Translation\TranslatableMessage;

/** Loupe asked the forge to merge, sync or change the base of the pull request, and the forge has not answered. */
final readonly class PullRequestForgeRequestPending implements Condition, MarkerEvidence, TimedCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.forge_request_pending';
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
        return null !== $facts->pullRequest?->forgeRequestedAt($facts->now);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_forge_request_pending' : 'workflow.waiting.pr_forge_request_pending');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $pullRequest = $facts->pullRequest;

        return null === $pullRequest ? null : PullRequestEvidence::of($pullRequest);
    }

    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        return $facts->pullRequest?->firstForgeRequestExpiresAt($facts->now);
    }
}
