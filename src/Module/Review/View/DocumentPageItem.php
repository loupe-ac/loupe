<?php

declare(strict_types=1);

namespace App\Module\Review\View;

use App\Module\Review\Entity\Document;

/** One row of a page of the document API: the document and its current version. */
final readonly class DocumentPageItem
{
    public function __construct(
        public Document $document,
        public int $versionNumber,
        public ?string $description,
    ) {
    }
}
