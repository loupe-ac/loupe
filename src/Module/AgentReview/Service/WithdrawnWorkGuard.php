<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Review\Service\WithdrawnWork;
use App\Module\Review\Service\WorkerSession;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(WithdrawnWork::class)]
#[AsAlias(WorkerSession::class)]
final readonly class WithdrawnWorkGuard implements WithdrawnWork, WorkerSession
{
    public function __construct(
        private CallerRun $callerRun,
        private WorkRequestRepository $workRequests,
    ) {
    }

    #[\Override]
    public function currentCallIsWithdrawn(Project $project): bool
    {
        $run = $this->callerRun->workerRun($project);

        return null !== $run && $this->runIsWithdrawn($run);
    }

    #[\Override]
    public function currentCallIsWorker(Project $project): bool
    {
        return $this->callerRun->isWorker($project);
    }

    /** True when the work request of the run is cancelled or expired. A run with no work request is never withdrawn. */
    public function runIsWithdrawn(WorkerRun $run): bool
    {
        $request = null === $run->workRequestId ? null : $this->workRequests->find($run->workRequestId);

        return null !== $request && \in_array($request->state, [WorkRequestState::Cancelled, WorkRequestState::Expired], true);
    }
}
