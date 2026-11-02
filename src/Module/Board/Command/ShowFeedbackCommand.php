<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowFeedbackCommand
{
    public function __construct(
        public Project $project,
        /** The id as the caller sent it. A value that is not a uuid names no item. */
        public string $feedbackId,
    ) {
    }
}
