<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\Service\HeldProjects;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Gives the claim of a bridge that stopped renewing back to every bridge, so
 * another one can take the work. A bridge that went quiet gets a short wait
 * first, so a network outage does not move the work. Answers how many requests
 * opened again.
 */
final readonly class ReopenLapsedWorkRequestsHandler
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private WorkRequestAnnouncer $announcer,
        private HeldProjects $heldProjects,
        private BridgeLiveness $liveness,
    ) {
    }

    public function __invoke(ReopenLapsedWorkRequestsCommand $command): int
    {
        // One transaction, so a reopened request always reaches the outbox.
        $reopened = $this->em->wrapInTransaction(function (): array {
            $now = $this->clock->now();
            // The key share locks last to the commit, so a hold that starts later waits for the reopen.
            $held = $this->heldProjects->among($this->workRequests->findProjectIdsOfLapsedClaims($now));
            $reopened = $this->workRequests->reopenLapsed($now, $held, $this->liveness->quietBefore($now));
            foreach ($reopened as $request) {
                $this->outbox->write($request->project, BridgeEventType::WORK_REQUEST, WorkRequestPayload::of($request));
            }
            $this->em->flush();

            return $reopened;
        });
        $this->announcer->announce(...$reopened);

        return \count($reopened);
    }
}
