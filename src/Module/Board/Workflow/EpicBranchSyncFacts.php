<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

final readonly class EpicBranchSyncFacts
{
    public function __construct(
        public bool $synced,
    ) {
    }
}
