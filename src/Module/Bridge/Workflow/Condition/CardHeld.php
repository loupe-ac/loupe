<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\HoldFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** A person held the agents of the card, so no bridge starts a worker on it. */
final readonly class CardHeld implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.held';
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
        return [HoldFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return null !== $facts->get(HoldFacts::class)->heldAt;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_held' : 'workflow.waiting.card_held');
    }
}
