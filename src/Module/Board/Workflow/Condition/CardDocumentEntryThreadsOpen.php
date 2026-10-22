<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;

/** A document with the tag in review had a thread that was not resolved when it was published, or has no entry check. */
final readonly class CardDocumentEntryThreadsOpen extends EntryCheckCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document.entry_threads_open';
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return array_any(
            $this->inReview($facts, $params),
            static fn (DocumentFacts $document): bool => !$document->hasEntryCheck() || $document->entryOpenThreads > 0,
        );
    }
}
