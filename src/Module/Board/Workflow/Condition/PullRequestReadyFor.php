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
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\TimedCondition;
use Symfony\Component\Translation\TranslatableMessage;

/** The pull request is not stacked, and stayed ready to merge with nothing pending for at least the delay, in minutes. */
final readonly class PullRequestReadyFor implements Condition, MarkerEvidence, TimedCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.ready_for';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.forge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('delay', ParameterType::Int, min: 1)];
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
        $endsAt = null === $pullRequest ? null : self::delayEndsAt($pullRequest, $params);

        return null !== $pullRequest
            && null !== $endsAt
            && null === $pullRequest->stackParent
            && $pullRequest->readyWithNothingPending($facts->now)
            && $endsAt <= $facts->now;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_ready_for' : 'workflow.waiting.pr_ready_for', ['%delay%' => (string) ParameterValue::int($params, 'delay')]);
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $pullRequest = $facts->pullRequest;

        return null === $pullRequest ? null : PullRequestEvidence::of($pullRequest, self::delayEndsAt($pullRequest, $params));
    }

    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $pullRequest = $facts->pullRequest;
        if (null === $pullRequest) {
            return null;
        }
        $endsAt = self::delayEndsAt($pullRequest, $params);
        $times = array_filter(
            [null !== $endsAt && $endsAt > $facts->now ? $endsAt : null, $pullRequest->forgeRequestsEndAt($facts->now)],
            static fn (?\DateTimeImmutable $time): bool => null !== $time,
        );

        return [] === $times ? null : min($times);
    }

    /** @param array<string, mixed> $params */
    private static function delayEndsAt(PullRequestFacts $pullRequest, array $params): ?\DateTimeImmutable
    {
        return $pullRequest->readySince?->add(new \DateInterval(\sprintf('PT%dM', ParameterValue::int($params, 'delay'))));
    }
}
