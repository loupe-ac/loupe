<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The checks ran on the head commit of the pull request. */
final readonly class PullRequestChecksOnHead implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.checks_on_head';
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
        return [EngineFact::PullRequest, EngineFact::PullRequestMergeGate];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return true === $facts->pullRequest?->checksOnHead;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_checks_on_head' : 'workflow.waiting.pr_checks_on_head');
    }
}
