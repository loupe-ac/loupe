<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service;

use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Stores the id of a new request on its run, and cancels the request when the run no longer waits for it. */
final readonly class DiscoveryWorkRequestStore
{
    private const array ENDED = [DiscoveryRunState::Done, DiscoveryRunState::Failed, DiscoveryRunState::Superseded];

    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private ProjectRepository $projects,
        private WithdrawWorkRequestHandler $withdrawWorkRequest,
        private EntityManagerInterface $em,
    ) {
    }

    /** Call it outside any transaction. $waitsIn is the state in which a run waits for the work of the request. */
    public function store(DiscoveryRun $run, WorkRequest $request, DiscoveryRunState $waitsIn): void
    {
        $requestId = $request->id ?? throw new \LogicException('A persisted work request has an id.');
        // The project lock orders this against a report and an approval: each reads the request, or the read below sees its change.
        $fresh = $this->em->wrapInTransaction(function () use ($run, $requestId): array {
            $this->projects->lockForWrite($run->project);
            $run->workRequestId = $requestId;
            $this->em->flush();

            return $this->discoveryRuns->freshStateOf($run);
        });

        // A report that answered the request can come from its own worker, which a cancel would stop.
        $answered = \in_array($requestId->toRfc4122(), $fresh['answeredWorkRequestIds'], true);
        if (\in_array($fresh['state'], self::ENDED, true) || ($waitsIn !== $fresh['state'] && !$answered)) {
            ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($requestId, WorkRequestState::Cancelled));
        }
    }
}
