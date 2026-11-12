<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;

final readonly class ListSeriesCommand
{
    public function __construct(
        public Project $project,
        /** The comparison key of the last series of the previous page of a walk. Null starts at the first series. */
        public ?string $afterNormalizedName = null,
        /** The most series to return. Null returns them all. */
        public ?int $limit = null,
    ) {
    }
}
