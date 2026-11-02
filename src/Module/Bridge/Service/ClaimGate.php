<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Workflow\Contract\WorkHolds;
use Psr\Clock\ClockInterface;

/**
 * Says why a bridge may not start a work request on an account now. The claim
 * asks it before it takes the request. Call it inside the transaction of the
 * claim, after the project row is locked, so a hold that commits first is seen.
 */
final readonly class ClaimGate
{
    public function __construct(
        private CardHolds $cardHolds,
        private WorkerRunRepository $workerRuns,
        private WorkHolds $workHolds,
        private AccountLimits $accountLimits,
        private ClockInterface $clock,
    ) {
    }

    /** Null when the bridge may start the request on the account. */
    public function refusalFor(Bridge $bridge, WorkRequest $request, ?string $account): ?WorkRequestRefusal
    {
        if (WorkSubject::CARD === $request->subjectType && $this->cardHolds->isHeld($request->project, $request->subjectId)) {
            return WorkRequestRefusal::CardHeld;
        }
        if (null !== $request->project->id && null !== $this->workHolds->heldProject($request->project->id)) {
            return WorkRequestRefusal::ProjectHeld;
        }
        if (WorkSubject::CARD === $request->subjectType && !self::opensSession($request) && $this->workerRuns->hasOpenInteractive($request->project, $request->subjectId)) {
            return WorkRequestRefusal::SessionOpen;
        }
        if (null !== $request->waitUntil && $request->waitUntil > $this->clock->now()) {
            return WorkRequestRefusal::Waiting;
        }
        if (null !== $account && isset($this->accountLimits->blockedOf($bridge)[$account])) {
            return WorkRequestRefusal::AccountBlocked;
        }

        return null;
    }

    /** A bridge launches a session request itself, so an open session never holds it back. */
    private static function opensSession(WorkRequest $request): bool
    {
        return \in_array($request->capability, [Bridge::CAPABILITY_SESSION, Bridge::CAPABILITY_INTERACTIVE], true);
    }
}
