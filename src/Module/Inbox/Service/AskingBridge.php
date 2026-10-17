<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** Finds the bridge of the worker that is asking, before the ask stores its items. */
final readonly class AskingBridge
{
    public function __construct(
        private BridgeRepository $bridges,
        private InboxAskRepository $inboxAsks,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    /**
     * The bridge that the call names, else the one the open ask of the session names, else the one of the first
     * run of the session. Only a bridge of the project owner that follows the project counts, because the
     * agent supplies the id.
     */
    public function find(Project $project, Uuid $sessionId, ?Uuid $bridgeId): ?Bridge
    {
        $id = $bridgeId
            ?? $this->inboxAsks->findOpenForSession($sessionId)->bridgeId
            ?? $this->workerRuns->findFirstOfSession($project, $sessionId)?->bridgeId;

        return null === $id ? null : $this->bridges->findOneFollowingProject((string) $project->id, $id->toRfc4122());
    }

    /**
     * The bridge of a stored to-do: the one the first ask that holds it names, else the one of the first run of
     * the session of the first ask that has a session.
     */
    public function findForItem(InboxItem $item): ?Bridge
    {
        $asks = $this->inboxAsks->findHolding($item);
        $id = array_find($asks, static fn ($ask): bool => null !== $ask->bridgeId)?->bridgeId;
        if (null === $id) {
            $sessionId = array_find($asks, static fn ($ask): bool => null !== $ask->sessionId)?->sessionId;
            $id = null === $sessionId ? null : $this->workerRuns->findFirstOfSession($item->project, $sessionId)?->bridgeId;
        }

        return null === $id ? null : $this->bridges->findOneFollowingProject((string) $item->project->id, $id->toRfc4122());
    }

    /** The bridge that ran the stopped run a wait item answers, when it still follows the project. */
    public function findForRun(WorkerRun $run): ?Bridge
    {
        return null === $run->bridgeId ? null : $this->bridges->findOneFollowingProject((string) $run->project->id, $run->bridgeId->toRfc4122());
    }
}
