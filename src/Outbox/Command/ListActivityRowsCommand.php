<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Module\Project\Entity\Project;
use App\Outbox\View\ActivityListQuery;

final readonly class ListActivityRowsCommand
{
    /** @param list<string> $ids the event ids that the open page shows */
    public function __construct(
        public Project $project,
        public ActivityListQuery $listQuery,
        public array $ids,
        public int $since,
    ) {
    }
}
