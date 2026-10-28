<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\Service\InteractiveStaleClose;
use Psr\Clock\ClockInterface;

final readonly class CloseStaleInteractiveRunsHandler
{
    /** Bounds one tick. A sweep that finds more leaves the rest to the next minute. */
    public const int BATCH_SIZE = 200;

    public function __construct(
        private InteractiveRuns $interactiveRuns,
        private InteractiveStaleClose $staleClose,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<WorkerRun> the runs it closed, none while the feature flag is off */
    public function __invoke(CloseStaleInteractiveRunsCommand $command): array
    {
        if (!$this->staleClose->enabled()) {
            return [];
        }

        return $this->interactiveRuns->closeStale($this->staleClose->quietBefore($this->clock->now()), self::BATCH_SIZE);
    }
}
