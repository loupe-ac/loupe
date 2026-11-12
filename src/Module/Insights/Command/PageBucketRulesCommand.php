<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Api\Pagination\PageRequest;
use App\Module\Project\Entity\Project;

final readonly class PageBucketRulesCommand
{
    public function __construct(
        public Project $project,
        public PageRequest $page,
    ) {
    }
}
