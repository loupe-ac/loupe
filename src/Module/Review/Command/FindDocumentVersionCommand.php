<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class FindDocumentVersionCommand
{
    public function __construct(
        public Document $document,
        public int $versionNumber,
    ) {
    }
}
