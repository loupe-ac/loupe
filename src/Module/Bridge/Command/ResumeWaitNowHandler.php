<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\Service\WorkerWaitEnder;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;

/**
 * A person ends the wait of a card before its time limit, and the worker resumes in the same session. A wait that ended while
 * its bridge was offline gets its resume at once, and the bridge runs it when it is back.
 */
final readonly class ResumeWaitNowHandler
{
    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private WorkerWaitEnder $ender,
        private ResumeWaitingRunHandler $resume,
    ) {
    }

    public function __invoke(ResumeWaitNowCommand $command): void
    {
        $wait = $this->workerWaits->findOpenOfCard($command->cardId);
        if (null !== $wait && $wait->project->requireId()->equals($command->project->requireId())) {
            $this->ender->end($wait, WorkerWaitEndReason::ResumedByPerson);

            return;
        }
        $stranded = $this->workerWaits->findStrandedOfCard($command->cardId);
        if (null === $stranded || !$stranded->project->requireId()->equals($command->project->requireId())) {
            throw new DomainErrors(['wait' => 'bridge.wait.error.no_open_wait']);
        }

        ($this->resume)(new ResumeWaitingRunCommand($stranded->id ?? throw new \LogicException('A stored wait has an id.')));
    }
}
