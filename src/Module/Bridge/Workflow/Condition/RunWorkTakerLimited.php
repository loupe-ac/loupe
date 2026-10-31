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

/** An open work request of the card waits because every online bridge that could take it has its account blocked by a usage limit. */
final readonly class RunWorkTakerLimited implements Condition, MarkerEvidence, TimedCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.work_taker_limited';
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
        return null !== $facts->get(WorkTakerFacts::class)->limited();
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_work_taker_limited' : 'workflow.waiting.run_work_taker_limited');
    }

    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $at = $facts->get(WorkTakerFacts::class)->changesAt();

        return null !== $at && $at > $facts->now ? $at : null;
    }

    /** The marker text shows the cause, the account, the window with its use, and the reset. A null use leaves the use empty. */
    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $limit = $facts->get(WorkTakerFacts::class)->limited()?->limit;
        if (null === $limit) {
            return null;
        }
        $usedPercent = $limit->window->reading->usedPercent;

        return new Evidence([
            '%cause%' => $limit->cause()->value,
            '%account%' => $limit->releasing->account,
            '%window%' => $limit->window->reading->window,
            '%use%' => null === $usedPercent ? '' : (int) round($usedPercent),
            '%stopAt%' => $limit->releasing->stopAt,
            '%resetsAt%' => $limit->resetsAt->getTimestamp(),
        ]);
    }
}
