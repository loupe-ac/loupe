<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use Symfony\Component\Uid\Uuid;

/**
 * A worker run ended and its cost comes from OpenRouter. The run has a pending
 * billed cost row. Dispatched after the commit, with ids only.
 */
final readonly class BilledCostAwaited
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $runId,
    ) {
    }
}
