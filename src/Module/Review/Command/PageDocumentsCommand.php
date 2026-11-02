<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Api\Pagination\PageRequest;
use App\Module\Project\Entity\Project;
use App\Module\Review\View\DocumentPageQuery;

final readonly class PageDocumentsCommand
{
    public function __construct(
        public Project $project,
        public DocumentPageQuery $query,
        public PageRequest $page,
    ) {
    }
}
