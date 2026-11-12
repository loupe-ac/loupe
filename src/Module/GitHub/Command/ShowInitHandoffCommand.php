<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowInitHandoffCommand
{
    public function __construct(
        public Project $project,
        /** The repository paths the success path named, joined by commas. */
        public string $repositories,
    ) {
    }
}
