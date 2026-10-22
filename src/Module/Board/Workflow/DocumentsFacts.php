<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\ListsDocuments;

/** The unarchived documents linked to the card. */
final readonly class DocumentsFacts implements ListsDocuments
{
    /** @param list<DocumentFacts> $documents */
    public function __construct(
        public array $documents,
    ) {
    }
}
