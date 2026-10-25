<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Review\Service\WithdrawnWork;
use App\Module\Review\Service\WorkerSession;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

#[AsAlias(WithdrawnWork::class)]
#[AsAlias(WorkerSession::class)]
final readonly class WithdrawnWorkGuard implements WithdrawnWork, WorkerSession
{
    public function __construct(
        private RequestStack $requests,
        private WorkerRunRepository $workerRuns,
        private WorkRequestRepository $workRequests,
    ) {
    }

    #[\Override]
    public function currentCallIsWithdrawn(Project $project): bool
    {
        $run = $this->currentWorkerRun($project);

        return null !== $run && $this->runIsWithdrawn($run);
    }

    #[\Override]
    public function currentCallIsWorker(Project $project): bool
    {
        return null !== $this->currentWorkerRun($project);
    }

    /** True when the work request of the run is cancelled or expired. A run with no work request is never withdrawn. */
    public function runIsWithdrawn(WorkerRun $run): bool
    {
        $request = null === $run->workRequestId ? null : $this->workRequests->find($run->workRequestId);

        return null !== $request && \in_array($request->state, [WorkRequestState::Cancelled, WorkRequestState::Expired], true);
    }

    private function currentWorkerRun(Project $project): ?WorkerRun
    {
        $session = $this->requests->getCurrentRequest()?->headers->get(AgentRunCause::SESSION_HEADER);
        if (null === $session || !Uuid::isValid($session)) {
            return null;
        }

        return $this->workerRuns->findNewestWorkerOfSession($project, Uuid::fromString($session));
    }
}
