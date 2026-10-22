<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\ColumnArrivalFacts;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;

/**
 * Each document with the tag in review was published at or after the arrival of the card in its current column.
 * A document with no entry check fails it, and so does a card with no known arrival.
 */
final readonly class CardDocumentPublishedInColumn extends EntryCheckCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document.published_in_column';
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [...parent::reads($params), ColumnArrivalFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $arrivedAt = $facts->get(ColumnArrivalFacts::class)->arrivedAt;
        $documents = $this->inReview($facts, $params);

        return null !== $arrivedAt
            && [] !== $documents
            && array_all($documents, static fn (DocumentFacts $document): bool => null !== $document->publishedAt && $document->publishedAt >= $arrivedAt);
    }
}
