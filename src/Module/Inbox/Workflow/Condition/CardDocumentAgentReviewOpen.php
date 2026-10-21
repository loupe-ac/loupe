<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow\Condition;

use App\Module\Inbox\Workflow\AgentReviewOpenFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** An agent asked the owner to review a document in review of the card, and that review item is open. A marker that binds a document reads that document alone. */
final readonly class CardDocumentAgentReviewOpen implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document.agent_review_open';
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
        return [AgentReviewOpenFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return array_any($facts->get(AgentReviewOpenFacts::class)->documentIds, $facts->readsDocument(...));
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_document_agent_review_open' : 'workflow.waiting.card_document_agent_review_open');
    }
}
