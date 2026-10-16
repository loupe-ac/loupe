<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\WorkTakerFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\TimedCondition;
use Symfony\Component\Translation\TranslatableMessage;

/** Every online bridge that could take an open work request of the card is paused, and no open request lacks a taker. */
final readonly class RunWorkTakerPaused implements Condition, TimedCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.work_taker_paused';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.bridge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [WorkTakerFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== $facts->get(WorkTakerFacts::class)->onlyPaused();
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_work_taker_paused' : 'workflow.waiting.run_work_taker_paused');
    }

    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $at = $facts->get(WorkTakerFacts::class)->changesAt();

        return null !== $at && $at > $facts->now ? $at : null;
    }
}
