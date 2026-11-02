<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\PageRequest;
use App\Module\Project\Entity\Project;

final readonly class PageBoardColumnsCommand
{
    public function __construct(
        public Project $project,
        public PageRequest $page,
    ) {
    }
}
