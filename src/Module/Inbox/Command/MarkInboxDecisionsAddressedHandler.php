<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Repository\InboxDecisionRepository;
use App\Module\Inbox\Service\InboxDecisionLog;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Marks the changes of the owner as acted on, so the card stops asking for a fix round. A change that is addressed already stays as it is. */
final readonly class MarkInboxDecisionsAddressedHandler
{
    public const string NO_CHANGE = 'inbox.decision.error.no_change';
    public const string OTHER_CARD = 'inbox.decision.error.other_card';
    public const string NOT_FOUND = 'inbox.decision.error.not_found';
    public const string STALE = 'inbox.decision.error.stale';

    public function __construct(
        private EntityManagerInterface $em,
        private InboxDecisionRepository $inboxDecisions,
        private WorkerRunRepository $workerRuns,
        private InboxDecisionLog $decisionLog,
        private CardEvaluations $evaluations,
        private Auditor $auditor,
    ) {
    }

    /** @return list<InboxDecision> the decisions this call marked */
    public function __invoke(MarkInboxDecisionsAddressedCommand $command): array
    {
        // The same refusal for "does not exist" and "belongs to another project".
        $decisionIds = array_map(Uuid::fromString(...), array_keys($command->versions));
        $decisions = $this->inboxDecisions->findByIdsInProject($command->project, $decisionIds);
        if (\count($decisions) !== \count($decisionIds)) {
            throw new DomainErrors(['decisionIds' => self::NOT_FOUND]);
        }
        $run = $command->credentialRun ?? $this->workerRuns->findNewestWorkerOfSession($command->project, $command->sessionId);
        $runCardId = $run?->cardId();
        foreach ($decisions as $decision) {
            if (null === $decision->changedAt) {
                throw new DomainErrors(['decisionIds' => self::NO_CHANGE]);
            }
            if (null !== $run && !$decision->card->id?->equals($runCardId)) {
                throw new DomainErrors(['decisionIds' => self::OTHER_CARD]);
            }
        }

        /** @var list<InboxDecision> $marked */
        $marked = $this->em->wrapInTransaction(function () use ($command, $decisions, $decisionIds, $run): array {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);
            $addressed = [];
            // The owner can change a decision while this call waits for the lock, so read each row again.
            $fresh = $this->inboxDecisions->readChangeState($decisionIds);
            foreach ($decisions as $decision) {
                $state = $fresh[(string) $decision->id] ?? null;
                if (null === $state || null === $state['changedAt'] || $state['changedAt']->format(InboxDecision::VERSION_FORMAT) !== ($command->versions[(string) $decision->id] ?? null)) {
                    throw new DomainErrors(['decisionIds' => self::STALE]);
                }
                $addressed[(string) $decision->id] = null !== $state['addressedAt'];
            }
            $now = new \DateTimeImmutable();
            $marked = [];
            foreach ($decisions as $decision) {
                if ($addressed[(string) $decision->id]) {
                    continue;
                }
                $decision->addressedAt = $now;
                $decision->addressedRunId = $run?->id;
                $marked[] = $decision;
            }
            $this->em->flush();

            return $marked;
        });

        $cardIds = [];
        foreach ($marked as $decision) {
            $cardId = $decision->card->id ?? throw new \LogicException('A stored card has an id.');
            $cardIds[(string) $cardId] = $cardId;
        }
        if ([] !== $cardIds) {
            $this->decisionLog->refresh($command->project, array_values($cardIds));
            if ($this->evaluations->isOn()) {
                $this->evaluations->forCards(array_values($cardIds), 'inbox.decision_addressed');
            }
        }
        foreach ($marked as $decision) {
            $this->auditor->record(
                'inbox.decision_addressed',
                AuditOutcome::Success,
                [
                    'decisionId' => (string) $decision->id,
                    'projectId' => (string) $command->project->id,
                    'cardId' => (string) $decision->card->id,
                    'runId' => null === $decision->addressedRunId ? null : (string) $decision->addressedRunId,
                ],
                new AuditSubject('inbox_decision', (string) $decision->id),
            );
        }

        return $marked;
    }
}
