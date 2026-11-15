<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;
use App\Module\Review\View\DocumentListQuery;

final readonly class ListDocumentRowsCommand
{
    /** @param list<string> $ids the documents the page shows */
    public function __construct(
        public Project $project,
        public DocumentListQuery $listQuery,
        public array $ids,
        public \DateTimeImmutable $since,
    ) {
    }
}
