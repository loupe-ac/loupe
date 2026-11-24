<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use Psr\Clock\ClockInterface;

/**
 * Asks again for the resume of each wait that ended while its bridge was offline, once that bridge sends its heartbeat.
 * The wait row is the record, so a resume command that expired meanwhile costs nothing. A wait whose resume still waits
 * on the bridge gets no second one. Answers how many waits it looked at.
 */
final readonly class ResumeStrandedWaitsHandler
{
    private const int LIMIT = 100;

    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private BridgeLiveness $liveness,
        private ResumeWaitingRunHandler $resume,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ResumeStrandedWaitsCommand $command): int
    {
        $waits = $this->workerWaits->findStrandedOfLiveBridges($this->liveness->quietBefore($this->clock->now()), self::LIMIT);
        foreach ($waits as $wait) {
            ($this->resume)(new ResumeWaitingRunCommand($wait->id ?? throw new \LogicException('A stored wait has an id.')));
        }

        return \count($waits);
    }
}
