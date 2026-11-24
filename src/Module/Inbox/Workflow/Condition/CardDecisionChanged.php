<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow\Condition;

use App\Module\Inbox\Workflow\DecisionChangedFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The owner changed a decision of the card, and no worker has marked the change addressed. */
final readonly class CardDecisionChanged implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.inbox.decision_changed';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.inbox';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [DecisionChangedFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(DecisionChangedFacts::class)->open;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage(($negated ? 'workflow.waiting.not.' : 'workflow.waiting.').'card_inbox_decision_changed');
    }
}
