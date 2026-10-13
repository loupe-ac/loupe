<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Fact\BlockerHoldFacts;
use Symfony\Component\Translation\TranslatableMessage;

/** A move rule of the card is false for an open blocker alone. */
final readonly class CardHeldByBlocker implements Condition, MarkerEvidence
{
    #[\Override]
    public static function key(): string
    {
        return 'card.rule.held_by_blocker';
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
        return [BlockerHoldFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== $facts->get(BlockerHoldFacts::class)->hold;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_rule_held_by_blocker' : 'workflow.waiting.card_rule_held_by_blocker');
    }

    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $hold = $facts->get(BlockerHoldFacts::class)->hold;

        return null === $hold ? null : new Evidence($hold->since, ['%number%' => $hold->blockerNumber, '%title%' => $hold->blockerTitle]);
    }
}
