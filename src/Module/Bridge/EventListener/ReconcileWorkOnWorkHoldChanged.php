<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\WorkflowRuleStates;
use App\Module\Workflow\Contract\WorkHolds;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Makes the open work of a project match its current hold, whatever the event says: two events can arrive
 * in the opposite order of their commits. A held project loses its open card requests, so none is left
 * stale. A project whose hold ended gets a new timeout on each open request that the hold found, and each held rule asks again.
 * Claimed work goes on. The card evaluation runs after the lock is gone.
 */
#[AsEventListener]
final readonly class ReconcileWorkOnWorkHoldChanged
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private WorkHolds $workHolds,
        private WorkRequestRepository $workRequests,
        private WithdrawWorkRequestHandler $withdraw,
        private WorkflowRuleStates $ruleStates,
        private CardEvaluations $evaluations,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ForgeRepositoryCheckChanged $event): void
    {
        $releasedCards = $this->em->wrapInTransaction(function () use ($event): array {
            $project = $this->projects->find($event->projectId);
            if (null === $project) {
                return [];
            }
            // The lock of a summary write: a claim that holds its key share finishes first, and a later one reads the hold.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            if (null !== $this->workHolds->heldProject($event->projectId)) {
                $withdrawn = [];
                foreach ($this->workRequests->findOpenCardRequestsOfProject($project) as $request) {
                    $id = $request->id ?? throw new \LogicException('A stored request has an id.');
                    if (($this->withdraw)(new WithdrawWorkRequestCommand($id, WorkRequestState::Cancelled, onlyIfOpen: true))) {
                        $withdrawn[] = $id;
                    }
                }
                // The rule that opened a withdrawn request still tracks it as live work, so the release of the hold would not ask again.
                $this->ruleStates->holdForWithdrawnRequests($withdrawn, $this->clock->now());
                // A request of another subject stays open, and the mark tells the end of the hold to restart its timeout.
                $this->workRequests->markOpenHeldForProject($event->projectId, $this->clock->now());

                return [];
            }

            $now = $this->clock->now();
            $this->workRequests->restartClockOfHeldForProject($event->projectId, $now);

            return $this->ruleStates->releaseWorkHolds($event->projectId, $now);
        });

        if ([] !== $releasedCards) {
            $this->evaluations->forCards($releasedCards);
        }
    }
}
