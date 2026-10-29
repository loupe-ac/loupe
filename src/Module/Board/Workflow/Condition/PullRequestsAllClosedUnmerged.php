<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\PullRequestState;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestsAllClosedUnmerged implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.pr.all_closed_unmerged';
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
        return [PullRequestList::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $pullRequests = $facts->pullRequests();

        return [] !== $pullRequests
            && array_all($pullRequests, static fn (PullRequestFacts $pullRequest): bool => PullRequestState::Closed === $pullRequest->state);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_all_closed_unmerged' : 'workflow.waiting.pr_all_closed_unmerged');
    }
}
