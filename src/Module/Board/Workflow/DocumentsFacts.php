<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\CardDocumentList;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\SwapsDocumentStatus;

/** The unarchived documents linked to the card. */
final readonly class DocumentsFacts implements SwapsDocumentStatus, CardDocumentList
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

    #[\Override]
    public function linkedDocuments(): array
    {
        return $this->documents;
    }

    #[\Override]
    public function withOnly(string $documentId): static
    {
        return new static(array_values(array_filter($this->documents, static fn (DocumentFacts $document): bool => $document->id === $documentId)));
    }
}
