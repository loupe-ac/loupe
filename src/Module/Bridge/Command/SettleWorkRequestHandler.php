<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\BridgeWorkMatchRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\AccountReadingRecorder;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Module\Bridge\ValueObject\UsageLimitHandBack;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Settles a claimed work request with the result of the bridge that holds the
 * claim, or opens it again when the bridge gives it back at a usage limit. The claim token fences the claim: after a lapse and a new claim, the
 * old holder gets claim_lost and the new holder's result stands. The same
 * result sent again answers the same and changes nothing.
 */
final readonly class SettleWorkRequestHandler
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private WorkRequestAnnouncer $announcer,
        private WorkSubjectHandlers $subjects,
        private BridgeRepository $bridges,
        private BridgeWorkMatchRepository $bridgeWorkMatches,
        private AccountReadingRecorder $readings,
    ) {
    }

    public function __invoke(SettleWorkRequestCommand $command): SettleWorkRequestResult
    {
        if (WorkRequestState::Open === $command->state) {
            $command->handBack ?? throw new \LogicException('A bridge gives a work request back with a hand-back.');
        } elseif (WorkRequestState::Done !== $command->state && WorkRequestState::Refused !== $command->state) {
            throw new \LogicException('A bridge settles a work request as done or refused, or gives it back as open.');
        }

        $result = $this->em->wrapInTransaction(function () use ($command): SettleWorkRequestResult {
            // Locked, so a withdrawal, the lapse sweep or a second result waits and then reads the final state.
            $request = $this->workRequests->findOneLocked($command->workRequestId);
            if (null === $request || null === $command->owner->id || !$command->owner->id->equals($request->project->owner->id)) {
                return new SettleWorkRequestResult(null, refusal: WorkRequestRefusal::NotFound);
            }
            if (!$this->holds($request, $command)) {
                return new SettleWorkRequestResult(null, refusal: WorkRequestRefusal::ClaimLost);
            }
            if ($command->state === $request->state) {
                return new SettleWorkRequestResult($request);
            }
            if (WorkRequestState::Open === $command->state) {
                return $this->handBack($request, $command->handBack ?? throw new \LogicException('A bridge gives a work request back with a hand-back.'), $command);
            }
            if (!$request->settle($command->state, $command->reason, $this->clock->now())) {
                return new SettleWorkRequestResult(null, refusal: WorkRequestRefusal::ClaimLost);
            }
            $this->outbox->write($request->project, BridgeEventType::WORK_REQUEST, WorkRequestPayload::of($request));
            $this->em->flush();

            return new SettleWorkRequestResult($request, settled: true);
        });

        $settled = $result->request;
        if ($result->handedBack && null !== $settled) {
            $this->auditor->record(
                'bridge.work_request_handed_back',
                AuditOutcome::Success,
                [
                    'workRequestId' => (string) $settled->id,
                    'projectId' => (string) $settled->project->id,
                    'subjectType' => $settled->subjectType,
                    'subjectId' => (string) $settled->subjectId,
                    'kind' => $settled->kind,
                    'bridgeId' => (string) $command->bridgeId,
                    'cause' => $settled->waitCause,
                    'account' => $settled->waitAccount,
                    'boundToBridge' => null !== $settled->targetBridgeId,
                ],
                new AuditSubject('work_request', (string) $settled->id),
            );
            $this->announcer->announce($settled);
        }
        if ($result->settled && null !== $settled) {
            $this->auditor->record(
                'bridge.work_request_settled',
                AuditOutcome::Success,
                [
                    'workRequestId' => (string) $settled->id,
                    'projectId' => (string) $settled->project->id,
                    'subjectType' => $settled->subjectType,
                    'subjectId' => (string) $settled->subjectId,
                    'kind' => $settled->kind,
                    'bridgeId' => (string) $command->bridgeId,
                    'state' => $settled->state->value,
                    'reason' => $settled->reason,
                ],
                new AuditSubject('work_request', (string) $settled->id),
            );
            $this->announcer->announce($settled);
            $this->subjects->for($settled->subjectType)?->onSettled($settled);
        }

        return $result;
    }

    /**
     * Opens the request again, so the engine sees no refusal and spends no retry. The reading that showed the limit
     * and the match of the bridge are stored in the same transaction, so the account reads as blocked at once.
     */
    private function handBack(WorkRequest $request, UsageLimitHandBack $handBack, SettleWorkRequestCommand $command): SettleWorkRequestResult
    {
        $now = $this->clock->now();
        if (!$request->handBackForUsageLimit($handBack->account, $handBack->sessionId, $handBack->hasAssistantTurn, $now)) {
            return new SettleWorkRequestResult(null, refusal: WorkRequestRefusal::ClaimLost);
        }

        // The claim proved the bridge exists. A bridge row goes only with its account, and then the match has no use.
        $bridge = $this->bridges->findOneByOwnerAndId($command->owner, $command->bridgeId);
        if (null !== $bridge) {
            $this->readings->record($bridge, [$handBack->report($now)]);
            $this->em->flush();
            $this->bridgeWorkMatches->put($bridge->owner->id ?? throw new \LogicException('A stored owner always has an id.'), $bridge->id, $command->workRequestId, $handBack->account);
        }
        $this->outbox->write($request->project, BridgeEventType::WORK_REQUEST, WorkRequestPayload::of($request));
        $this->em->flush();

        return new SettleWorkRequestResult($request, handedBack: true);
    }

    /** Settle keeps the bridge and the token, so a repeat after the settlement still matches. */
    private function holds(WorkRequest $request, SettleWorkRequestCommand $command): bool
    {
        return \in_array($request->state, [WorkRequestState::Claimed, WorkRequestState::Done, WorkRequestState::Refused], true)
            && $command->bridgeId->equals($request->bridgeId)
            && $command->claimToken->equals($request->claimToken);
    }
}
