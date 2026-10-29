<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Fact\WorkHoldFacts;
use Symfony\Component\Translation\TranslatableMessage;

/** A rule of the card waits to ask for work, because the project holds its new work. */
final readonly class CardHeldByWorkHold implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.rule.held_by_work_hold';
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
        return [WorkHoldFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== $facts->get(WorkHoldFacts::class)->since;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_rule_held_by_work_hold' : 'workflow.waiting.card_rule_held_by_work_hold');
    }
}
