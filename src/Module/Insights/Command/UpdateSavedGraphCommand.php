<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

final readonly class UpdateSavedGraphCommand
{
    public function __construct(
        public Project $project,
        public string $graphId,
        /** Null or blank keeps the name of the graph. */
        public ?string $name,
        /** @var array<string, string> the posted controls of the Metrics page, by name */
        public array $settings,
    ) {
    }
}
