<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

final readonly class SidebarCountsView
{
    /** @param list<string> $streams one turbo-stream element for each pill, empty when nothing changed */
    public function __construct(
        public bool $unchanged,
        public array $streams,
    ) {
    }
}
