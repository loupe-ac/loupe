<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

/** A setting left null keeps the value that the save finds under its lock. */
final readonly class SaveBoardAutomationSettingsCommand
{
    public function __construct(
        public Project $project,
        public ?bool $enabled = null,
        public ?int $stuckDelayMinutes = null,
        public ?bool $approvalCoversConflictResolution = null,
    ) {
    }
}
