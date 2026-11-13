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

    /** @return list<WorkerRun> the runs it closed. An init run closes whatever the feature flag says, and no other run closes while it is off */
    public function __invoke(CloseStaleInteractiveRunsCommand $command): array
    {
        $now = $this->clock->now();
        $closed = $this->interactiveRuns->closeStaleInit($this->staleClose->initQuietBefore($now), self::BATCH_SIZE);
        if (!$this->staleClose->enabled()) {
            return $closed;
        }

        return [...$closed, ...$this->interactiveRuns->closeStale($this->staleClose->quietBefore($now), self::BATCH_SIZE)];
    }
}
