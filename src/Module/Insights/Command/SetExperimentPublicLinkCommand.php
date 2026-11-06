<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

final readonly class SetExperimentPublicLinkCommand
{
    public function __construct(
        public Project $project,
        public string $experiment,
        /** The switch of the share dialog: true shares the experiment, false stops sharing it. */
        public bool $enabled,
        /** Markdown for the visitor. A blank note clears it. */
        public ?string $note,
    ) {
    }
}
