<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\ListsDocuments;
use App\Module\Workflow\Contract\SwapsDocumentStatus;

/** The unarchived documents linked to the card. */
final readonly class DocumentsFacts implements SwapsDocumentStatus, ListsDocuments
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
            static fn (DocumentFacts $document): DocumentFacts => $document->id === $documentId ? $document->withStatus($status) : $document,
            $this->documents,
        ));
    }
}
