<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Workflow\Contract\WorkHolds;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Cancels or expires a live work request, and tells the bridges through the
 * outbox. Answers false when the request is unknown or already settled.
 */
final readonly class WithdrawWorkRequestHandler
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private WorkRequestAnnouncer $announcer,
        private WorkSubjectHandlers $subjects,
        private WorkHolds $workHolds,
    ) {
    }

    public function __invoke(WithdrawWorkRequestCommand $command): bool
    {
        if (WorkRequestState::Cancelled !== $command->state && WorkRequestState::Expired !== $command->state) {
            throw new \LogicException('A work request withdraws to cancelled or expired.');
        }

        $withdrawn = $this->em->wrapInTransaction(function () use ($command): ?WorkRequest {
            if (null !== $command->deadline && !$this->outsideWorkHold($command->workRequestId)) {
                return null;
            }
            // Locked, so a claim or a settle that races this one waits and then finds the final state.
            $request = $this->workRequests->findOneLocked($command->workRequestId);
            if (null === $request
                || ($command->onlyIfOpen && WorkRequestState::Open !== $request->state)
                || (null !== $command->deadline && !self::waitedSince($request, $command->deadline))
                || !$request->withdraw($command->state, $this->clock->now(), $command->reason)) {
                return null;
            }
            $this->outbox->write($request->project, BridgeEventType::WORK_REQUEST, WorkRequestPayload::of($request));
            $this->em->flush();

            return $request;
        });
        if (null === $withdrawn) {
            return false;
        }

        $this->auditor->record(
            'bridge.work_request_withdrawn',
            AuditOutcome::Success,
            [
                'workRequestId' => (string) $withdrawn->id,
                'projectId' => (string) $withdrawn->project->id,
                'subjectType' => $withdrawn->subjectType,
                'subjectId' => (string) $withdrawn->subjectId,
                'kind' => $withdrawn->kind,
                'state' => $withdrawn->state->value,
            ],
            new AuditSubject('work_request', (string) $withdrawn->id),
        );
        $this->announcer->announce($withdrawn);
        if (WorkRequestState::Expired === $withdrawn->state) {
            $this->subjects->for($withdrawn->subjectType)?->onExpired($withdrawn);
        }

        return true;
    }

    /**
     * The key share read waits for a hold write, and comes before the row lock in the order of the hold listener.
     * A hold that commits later waits for this transaction.
     */
    private function outsideWorkHold(Uuid $workRequestId): bool
    {
        $projectId = $this->workRequests->find($workRequestId)?->project->id;

        return null !== $projectId && null === $this->workHolds->heldProject($projectId);
    }

    /** The hold mark stays until the end of the hold restarts the timeout, so a marked request has not waited yet. */
    private static function waitedSince(WorkRequest $request, \DateTimeImmutable $deadline): bool
    {
        return WorkRequestState::Open === $request->state
            && null === $request->workHoldSince
            && ($request->reopenedAt ?? $request->createdAt) <= $deadline;
    }
}
