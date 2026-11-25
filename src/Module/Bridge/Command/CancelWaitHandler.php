<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\Service\WorkerWaitEnder;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;

/**
 * A person cancels the wait of a card. The worker stops and does not resume. A wait that ended while its bridge was offline
 * has nothing to end, so the cancel stops its parked run.
 */
final readonly class CancelWaitHandler
{
    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private WorkerWaitEnder $ender,
    ) {
    }

    public function __invoke(CancelWaitCommand $command): void
    {
        $wait = $this->workerWaits->findOpenOfCard($command->cardId);
        if (null !== $wait && $wait->project->requireId()->equals($command->project->requireId())) {
            $this->ender->end($wait, WorkerWaitEndReason::CancelledByPerson);

            return;
        }
        $stranded = $this->workerWaits->findStrandedOfCard($command->cardId);
        if (null === $stranded || !$stranded->project->requireId()->equals($command->project->requireId()) || !$this->ender->stopStranded($stranded, WorkerWaitEndReason::CancelledByPerson)) {
            throw new DomainErrors(['wait' => 'bridge.wait.error.no_open_wait']);
        }
    }
}
