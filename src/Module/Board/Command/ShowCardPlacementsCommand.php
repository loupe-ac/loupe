<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowCardPlacementsCommand
{
    public function __construct(
        public Project $project,
        /** @var list<string> card ids, in the order the page asked for them, with no repeat */
        public array $cardIds,
    ) {
    }
}
