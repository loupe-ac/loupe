<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use App\Module\Workflow\Contract\TimedCondition;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The pull request is ready to merge and waits for the branch it is stacked on. With "known", a card links the open
 * parent pull request. With "unknown", only the branch is known.
 */
final readonly class PullRequestStackParent implements Condition, MarkerEvidence, TimedCondition
{
    private const string KNOWN = 'known';

    private const string UNKNOWN = 'unknown';

    #[\Override]
    public static function key(): string
    {
        return 'pr.stack_parent';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.forge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('parent', ParameterType::String, required: false, choices: [self::KNOWN, self::UNKNOWN])];
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
        $parent = $pullRequest?->stackParent;
        if (null === $pullRequest || null === $parent || !$pullRequest->readyWithNothingPending($facts->now)) {
            return false;
        }

        return match (ParameterValue::optionalString($params, 'parent')) {
            self::KNOWN => $parent->known(),
            self::UNKNOWN => !$parent->known(),
            default => true,
        };
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        $suffix = match (ParameterValue::optionalString($params, 'parent')) {
            self::KNOWN => 'pr_stack_parent_known',
            self::UNKNOWN => 'pr_stack_parent_unknown',
            default => 'pr_stack_parent',
        };

        return new TranslatableMessage(($negated ? 'workflow.waiting.not.' : 'workflow.waiting.').$suffix);
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $pullRequest = $facts->pullRequest;
        $parent = $pullRequest?->stackParent;
        if (null === $pullRequest || null === $parent) {
            return null;
        }

        return PullRequestEvidence::of($pullRequest, $pullRequest->readySince, $parent->known()
            ? ['%parent_number%' => $parent->card ?? 0, '%pull_request%' => $parent->pullRequest ?? 0]
            : ['%branch%' => $parent->branch]);
    }

    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        return $facts->pullRequest?->firstForgeRequestExpiresAt($facts->now);
    }
}
