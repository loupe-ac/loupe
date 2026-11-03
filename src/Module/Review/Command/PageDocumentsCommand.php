<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Api\Pagination\PageRequest;
use App\Module\Project\Entity\Project;
use App\Module\Review\View\DocumentPageQuery;

/** @param bool $links read the references and the referencing documents of the page */
final readonly class PageDocumentsCommand
{
    public function __construct(
        public Project $project,
        public DocumentPageQuery $query,
        public PageRequest $page,
        public bool $links = true,
    ) {
    }
}
