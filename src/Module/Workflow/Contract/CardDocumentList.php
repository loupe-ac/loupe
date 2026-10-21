<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The documents linked to the card, which a marker can bind one at a time, as it binds one pull request. */
interface CardDocumentList
{
    /** @return list<DocumentFacts> */
    public function linkedDocuments(): array;

    /** A copy that holds only the document. */
    public function withOnly(string $documentId): static;
}
