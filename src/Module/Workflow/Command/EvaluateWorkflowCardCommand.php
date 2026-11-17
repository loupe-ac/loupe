<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use Symfony\Component\Uid\Uuid;

final readonly class EvaluateWorkflowCardCommand
{
    public function __construct(
        public Uuid $cardId,
        /** True for a card that a full sweep queued with no mark, so a change that the check finds is a miss of the marks. */
        public bool $reportMiss = false,
    ) {
    }
}
