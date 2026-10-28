<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\TimedCondition;
use App\Module\Workflow\Fact\ScheduledRetryFacts;
use Symfony\Component\Translation\TranslatableMessage;

/** A refused rule of the card waits for the backoff before its retry, and the retry is still to come. */
final readonly class CardRetryScheduled implements Condition, MarkerEvidence, TimedCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.rule.retry_scheduled';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [ScheduledRetryFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== $facts->get(ScheduledRetryFacts::class)->nextAfter($facts->now);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_rule_retry_scheduled' : 'workflow.waiting.card_rule_retry_scheduled');
    }

    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        return $facts->get(ScheduledRetryFacts::class)->nextAfter($facts->now);
    }

    /** The clock reads as the card state texts read their start time: the time alone today, else the date too. */
    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $dueAt = $facts->get(ScheduledRetryFacts::class)->nextAfter($facts->now);
        if (null === $dueAt) {
            return null;
        }
        $today = $facts->now->setTimezone($dueAt->getTimezone())->format('Y-m-d') === $dueAt->format('Y-m-d');

        return new Evidence(['%due%' => $dueAt->format($today ? 'H:i' : 'M j, H:i')]);
    }
}
