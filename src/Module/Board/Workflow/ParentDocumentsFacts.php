<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\SwapsDocumentStatus;

/** The unarchived documents linked to the parent of the card, empty with no parent. */
final readonly class ParentDocumentsFacts implements SwapsDocumentStatus
{
    /** @param list<DocumentFacts> $documents */
    public function __construct(
        public array $documents,
    ) {
    }

    #[\Override]
    public function withDocumentStatus(string $documentId, string $status): static
    {
        return new static(array_map(
            static fn (DocumentFacts $document): DocumentFacts => $document->id === $documentId ? new DocumentFacts($document->tags, $status, $document->id) : $document,
            $this->documents,
        ));
    }
}
