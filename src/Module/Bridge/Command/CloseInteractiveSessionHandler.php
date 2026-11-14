<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkerRunCloseReason;
use App\Module\Project\Contract\ProjectMilestones;

final readonly class CloseInteractiveSessionHandler
{
    public function __construct(
        private InteractiveRuns $interactiveRuns,
        private WorkerRunRepository $workerRuns,
        private ProjectMilestones $milestones,
    ) {
    }

    /** The number of runs it closed, which is 0 when the owner holds no open run of the session. */
    public function __invoke(CloseInteractiveSessionCommand $command): int
    {
        $closed = $this->interactiveRuns->closeSession($command->owner, $command->sessionId, WorkerRunCloseReason::SessionEnded);

        if ($command->finished) {
            // Closed runs count too, because a stale close can end an init run while the laptop sleeps.
            foreach ($this->workerRuns->findInitProjectIdsOfSession($command->owner, $command->sessionId) as $projectId) {
                $this->milestones->reach($projectId, ProjectMilestones::INIT_FINISHED);
            }
        }

        return $closed;
    }
}
