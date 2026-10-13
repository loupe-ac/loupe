<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\CardActivityFacts;
use App\Module\Board\Workflow\StuckDelayFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use App\Module\Workflow\Contract\TimedCondition;
use Symfony\Component\Translation\TranslatableMessage;

/** Nothing happened on the card for at least the delay, in minutes, or the stuck delay of the project with no delay. */
final readonly class CardQuietFor implements Condition, MarkerEvidence, TimedCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.quiet_for';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('delay', ParameterType::Int, required: false, min: 1)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [CardActivityFacts::class, ...StuckDelayFacts::readsFor($params)];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $endsAt = self::delayEndsAt($facts, $params);

        return null !== $endsAt && $endsAt <= $facts->now;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        $delay = ParameterValue::optionalInt($params, 'delay');
        if (null === $delay) {
            return new TranslatableMessage($negated ? 'workflow.waiting.not.card_quiet_for_stuck_delay' : 'workflow.waiting.card_quiet_for_stuck_delay');
        }

        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_quiet_for' : 'workflow.waiting.card_quiet_for', ['%delay%' => (string) $delay]);
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $endsAt = self::delayEndsAt($facts, $params);

        return null === $endsAt ? null : new Evidence($endsAt);
    }

    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $endsAt = self::delayEndsAt($facts, $params);

        return null !== $endsAt && $endsAt > $facts->now ? $endsAt : null;
    }

    /** @param array<string, mixed> $params */
    private static function delayEndsAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        return $facts->get(CardActivityFacts::class)->newestEventAt?->add(new \DateInterval(\sprintf('PT%dM', StuckDelayFacts::minutesFor($facts, $params))));
    }
}
