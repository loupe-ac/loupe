<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;

final readonly class ListTagsCommand
{
    public function __construct(
        public Project $project,
        /** The name of the last tag of the previous page of a walk. Null starts at the first tag. */
        public ?string $afterName = null,
        /** The most tags to return. Null returns them all. */
        public ?int $limit = null,
    ) {
    }
}
