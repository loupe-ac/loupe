<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;

/** A document with the tag in review had a decision with no answer when it entered review, or has no entry check. */
final readonly class CardDocumentEntryDecisionsOpen extends EntryCheckCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document.entry_decisions_open';
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return array_any(
            $this->inReview($facts, $params),
            static fn (DocumentFacts $document): bool => !$document->hasEntryCheck() || $document->entryUnansweredDecisions > 0,
        );
    }
}
