<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\View\ProjectListItem;

final readonly class ListProjectRowsView
{
    /**
     * @param list<ProjectListItem> $items   the shown rows that still exist
     * @param list<string>          $goneIds the shown rows that no longer exist
     */
    public function __construct(
        public array $items,
        public array $goneIds,
        public int $newerCount,
    ) {
    }
}
