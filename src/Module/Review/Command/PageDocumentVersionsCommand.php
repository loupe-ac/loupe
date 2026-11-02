<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Api\Pagination\CreatedSort;
use App\Api\Pagination\PageRequest;
use App\Module\Review\Entity\Document;

final readonly class PageDocumentVersionsCommand
{
    public function __construct(
        public Document $document,
        public PageRequest $page,
        public CreatedSort $sort,
    ) {
    }
}
