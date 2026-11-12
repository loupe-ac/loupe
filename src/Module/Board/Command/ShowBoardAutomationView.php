<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** The automation settings of a project board, with the defaults when the project saved none. */
final readonly class ShowBoardAutomationView
{
    public function __construct(
        public bool $enabled,
        public int $stuckDelayMinutes,
        public bool $approvalCoversConflictResolution,
    ) {
    }
}
