<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\NamesMarkerSubject;
use App\Module\Workflow\Contract\PullRequestState;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestOpen implements Condition, MarkerEvidence, NamesMarkerSubject
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.open';
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
        return [EngineFact::PullRequestSubject];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return PullRequestState::Open === $facts->pullRequest?->state;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_open' : 'workflow.waiting.pr_open');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $pullRequest = $facts->pullRequest;

        return null === $pullRequest ? null : PullRequestEvidence::of($pullRequest);
    }

    #[\Override]
    public function subjectType(): MarkerSubjectType
    {
        return MarkerSubjectType::PullRequest;
    }
}
