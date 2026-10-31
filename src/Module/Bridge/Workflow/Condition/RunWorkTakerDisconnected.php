<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\WorkTakerFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\TimedCondition;
use Symfony\Component\Translation\TranslatableMessage;

/** A work request of the card is claimed by a bridge that went quiet, or waits after a bridge lost its network until that bridge or another one is back. */
final readonly class RunWorkTakerDisconnected implements Condition, MarkerEvidence, TimedCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.work_taker_disconnected';
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
        return null !== $facts->get(WorkTakerFacts::class)->disconnected();
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_work_taker_disconnected' : 'workflow.waiting.run_work_taker_disconnected');
    }

    /** The end of the claim or of the wait, or sooner when another reading changes. */
    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $at = $facts->get(WorkTakerFacts::class)->changesAt();

        return null !== $at && $at > $facts->now ? $at : null;
    }

    /** The marker text names the machine, or reads without one when the reading has none. */
    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $reading = $facts->get(WorkTakerFacts::class)->disconnected();

        return null === $reading ? null : new Evidence(['%machine%' => $reading->bridgeLabel ?? '']);
    }
}
