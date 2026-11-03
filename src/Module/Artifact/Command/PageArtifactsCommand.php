<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Api\Pagination\CreatedSort;
use App\Api\Pagination\PageRequest;
use App\Module\Project\Entity\Project;

final readonly class PageArtifactsCommand
{
    public function __construct(
        public Project $project,
        public bool $includeArchived,
        public PageRequest $page,
        public CreatedSort $sort,
        public ArtifactRowReads $reads = new ArtifactRowReads(),
    ) {
    }
}
