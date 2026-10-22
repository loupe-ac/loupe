<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\CardActivityFacts;
use App\Module\Board\Workflow\ChildActivityFacts;
use App\Module\Board\Workflow\StuckDelayFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use App\Module\Workflow\Contract\TimedCondition;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Nothing happened on the card for at least the delay, in minutes, or the stuck delay of the project with no delay.
 * With `children`, the newest event of any child counts too, and the condition is false while a child has an open worker run.
 */
final readonly class CardQuietFor implements Condition, TimedCondition
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
        return [new Parameter('delay', ParameterType::Int, required: false, min: 1), new Parameter('children', ParameterType::Bool, required: false)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [CardActivityFacts::class, ...(self::countsChildren($params) ? [ChildActivityFacts::class] : []), ...StuckDelayFacts::readsFor($params)];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        if (self::countsChildren($params) && $facts->get(ChildActivityFacts::class)->runOpen) {
            return false;
        }
        $endsAt = self::delayEndsAt($facts, $params);

        return null !== $endsAt && $endsAt <= $facts->now;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        $delay = ParameterValue::optionalInt($params, 'delay');
        $suffix = self::countsChildren($params) ? '_with_children' : '';
        if (null === $delay) {
            return new TranslatableMessage($negated ? 'workflow.waiting.not.card_quiet_for'.$suffix.'_stuck_delay' : 'workflow.waiting.card_quiet_for'.$suffix.'_stuck_delay');
        }

        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_quiet_for'.$suffix : 'workflow.waiting.card_quiet_for'.$suffix, ['%delay%' => (string) $delay]);
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
        return self::newestEventAt($facts, $params)?->add(new \DateInterval(\sprintf('PT%dM', StuckDelayFacts::minutesFor($facts, $params))));
    }

    /** @param array<string, mixed> $params */
    private static function newestEventAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $own = $facts->get(CardActivityFacts::class)->newestEventAt;
        if (!self::countsChildren($params)) {
            return $own;
        }
        $child = $facts->get(ChildActivityFacts::class)->newestEventAt;

        return null === $own || (null !== $child && $child > $own) ? $child : $own;
    }

    /** @param array<string, mixed> $params */
    private static function countsChildren(array $params): bool
    {
        return true === ($params['children'] ?? false);
    }
}
