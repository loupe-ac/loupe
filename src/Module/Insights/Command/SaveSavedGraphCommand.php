<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

final readonly class SaveSavedGraphCommand
{
    public function __construct(
        public Project $project,
        /** Null or blank takes the title of the graph. */
        public ?string $name,
        /** @var array<string, string> the posted controls of the Metrics page, by name */
        public array $settings,
    ) {
    }
}
