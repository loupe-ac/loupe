<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

/** What the design review rules read about one card. */
final readonly class DocumentReviewFacts
{
    /** @param list<DocumentReviewState> $documents the linked draft design documents of the card */
    public function __construct(
        public array $documents,
    ) {
    }

    /** @return list<DocumentReviewState> */
    public function withTag(string $tag): array
    {
        return array_values(array_filter($this->documents, static fn (DocumentReviewState $document): bool => $document->hasTag($tag)));
    }
}
