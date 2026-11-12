<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Project\Entity\Project;

/** The changes to one saved graph. A null field keeps what the graph holds. */
final readonly class UpdateSavedGraphFieldsCommand
{
    public function __construct(
        public Project $project,
        public string $graphId,
        /** A blank name keeps the name of the graph. */
        public ?string $name = null,
        /** @var array<string, string>|null the whole set of controls of the Metrics page, by name */
        public ?array $settings = null,
        /** A partial change, merged with the settings the graph keeps once the project lock is held. It excludes `settings`. */
        public ?SavedGraphSettingsChange $change = null,
        public ?SavedGraphWidth $width = null,
        public ?bool $showLegend = null,
        /** The place among the graphs on show, counted from 0. A place past the last puts the graph last. */
        public ?int $position = null,
    ) {
    }
}
