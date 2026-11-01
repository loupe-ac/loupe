<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Bridge\Event\BridgeAccountReadingsStored;
use App\Module\Inbox\Service\AccountLimitReconciler;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A new reading can put an account at its hard limit or lift it, so the notice of the account follows at once. */
#[AsEventListener]
final readonly class ReconcileAccountLimitsOnBridgeAccountReadingsStored
{
    public function __construct(
        private ProjectRepository $projects,
        private AccountLimitReconciler $reconciler,
    ) {
    }

    public function __invoke(BridgeAccountReadingsStored $event): void
    {
        foreach ($event->projectIds as $projectId) {
            // A project deleted after the heartbeat has nothing left to reconcile.
            $project = $this->projects->find($projectId);
            if (null !== $project) {
                $this->reconciler->reconcile($project);
            }
        }
    }
}
