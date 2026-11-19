<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class FullSweepWorkflowCardsCommand
{
    /** @param bool $force run now, whatever the age of the last full sweep */
    public function __construct(
        public bool $force = false,
    ) {
    }
}
