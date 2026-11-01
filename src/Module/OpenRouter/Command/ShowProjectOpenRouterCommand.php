<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowProjectOpenRouterCommand
{
    public function __construct(
        public Project $project,
    ) {
    }
}
