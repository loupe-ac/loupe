<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\WorkTakerFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\TimedCondition;
use Symfony\Component\Translation\TranslatableMessage;

/** An open work request of the card has an online bridge that follows the project, and none of those bridges can run the work. */
final readonly class RunWorkNoCapableTaker implements Condition, TimedCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.work_no_capable_taker';
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
        return null !== $facts->get(WorkTakerFacts::class)->notCapable();
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_work_no_capable_taker' : 'workflow.waiting.run_work_no_capable_taker');
    }

    #[\Override]
    public function changesAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $at = $facts->get(WorkTakerFacts::class)->changesAt();

        return null !== $at && $at > $facts->now ? $at : null;
    }
}
