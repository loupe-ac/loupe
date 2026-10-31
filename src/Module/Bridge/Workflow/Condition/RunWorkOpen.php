<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\Workflow\WorkTakerFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** A work request of the card is open, which means no bridge claimed it yet. A claimed request does not count. */
final readonly class RunWorkOpen implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.work_open';
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
        return array_any($facts->get(WorkTakerFacts::class)->readings, static fn (WorkTakerReading $reading): bool => !$reading->claimed);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_work_open' : 'workflow.waiting.run_work_open');
    }
}
