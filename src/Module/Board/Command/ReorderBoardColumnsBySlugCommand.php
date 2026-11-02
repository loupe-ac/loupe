<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

final readonly class ReorderBoardColumnsBySlugCommand
{
    /**
     * @param array<mixed> $slugs the slug of every column except backlog, each once, in the new order
     */
    public function __construct(
        public Project $project,
        public array $slugs,
    ) {
    }
}
