<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Inbox\Repository\InboxBridgeOutageRepository;
use App\Module\Inbox\Service\BridgeOutageReconciler;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Project\Repository\ProjectRepository;

/**
 * Visits each project with an open work request about a card, and each project
 * with an open outage. While the inbox is off, only an open outage has work,
 * because its notice must close.
 */
final readonly class ReconcileBridgeOutagesHandler
{
    public function __construct(
        private InboxAvailability $inbox,
        private ProjectRepository $projects,
        private WorkRequestRepository $workRequests,
        private InboxBridgeOutageRepository $inboxBridgeOutages,
        private BridgeOutageReconciler $reconciler,
    ) {
    }

    /** @return int how many projects it reconciled */
    public function __invoke(ReconcileBridgeOutagesCommand $command): int
    {
        $projectIds = $this->inboxBridgeOutages->findProjectIdsWithOpenOutage();
        if ($this->inbox->isEnabled()) {
            $projectIds = array_values(array_unique([...$projectIds, ...$this->workRequests->findProjectIdsWithOpenCardRequest()]));
        }

        $reconciled = 0;
        foreach ($projectIds as $projectId) {
            // A project deleted after the read has nothing left to reconcile.
            $project = $this->projects->find($projectId);
            if (null === $project) {
                continue;
            }
            $this->reconciler->reconcile($project);
            ++$reconciled;
        }

        return $reconciled;
    }
}
