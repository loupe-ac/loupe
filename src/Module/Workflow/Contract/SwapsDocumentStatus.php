<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Facts that hold document statuses, and can give a copy with one document at another status. */
interface SwapsDocumentStatus
{
    /** A copy with the document at the status. The same facts when they hold no such document. */
    public function withDocumentStatus(string $documentId, string $status): static;
}
