<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\InboxLimits;
use App\Module\Inbox\Service\InboxDecisionLog;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Records that the owner chose another answer for a decision. The change stays open until the worker marks it
 * addressed. A second change replaces the first and opens again. On a defaulted decision this is the reopen.
 */
final readonly class ChangeInboxDecisionHandler
{
    public const string CHOICE_BLANK = 'inbox.decision.error.choice_blank';
    public const string CHOICE_TOO_LONG = 'inbox.decision.error.choice_too_long';
    public const string CHOICE_UNCHANGED = 'inbox.decision.error.choice_unchanged';
    public const string NOTE_TOO_LONG = 'inbox.decision.error.note_too_long';

    public function __construct(
        private EntityManagerInterface $em,
        private InboxDecisionLog $decisionLog,
        private CardEvaluations $evaluations,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ChangeInboxDecisionCommand $command): ChangeInboxDecisionView
    {
        $decision = $command->decision;
        $choice = trim($command->choice);
        $note = null === $command->note || '' === trim($command->note) ? null : trim($command->note);
        if ('' === $choice) {
            throw new DomainErrors(['choice' => self::CHOICE_BLANK]);
        }
        if (mb_strlen($choice) > InboxLimits::MAX_OPTION_LENGTH) {
            throw new DomainErrors(['choice' => self::CHOICE_TOO_LONG]);
        }
        if (null !== $note && mb_strlen($note) > InboxLimits::MAX_DECISION_NOTE_LENGTH) {
            throw new DomainErrors(['note' => self::NOTE_TOO_LONG]);
        }
        if ($choice === ($decision->changedChoice ?? $decision->choice)) {
            throw new DomainErrors(['choice' => self::CHOICE_UNCHANGED]);
        }

        $this->em->wrapInTransaction(function () use ($decision, $command, $choice, $note): void {
            // The project lock orders a change against an ask or a close of the same project.
            $this->em->lock($decision->project, LockMode::PESSIMISTIC_WRITE);
            $decision->changedChoice = $choice;
            $decision->changedNote = $note;
            $decision->changedAt = new \DateTimeImmutable();
            $decision->changedBy = $command->user;
            // A new change is news for the worker again, also after the earlier one was addressed.
            $decision->addressedAt = null;
            $decision->addressedRunId = null;
            $decision->madeBy = InboxDecisionMadeBy::Changed;
            $this->em->flush();
        });

        $cardId = $decision->card->id ?? throw new \LogicException('A stored card has an id.');
        $this->decisionLog->refresh($decision->project, [$cardId]);
        if ($this->evaluations->isOn()) {
            $this->evaluations->forCards([$cardId], 'inbox.decision_changed');
        }

        // No choice and no note: they are sentences the owner wrote.
        $this->auditor->record(
            'inbox.decision_changed',
            AuditOutcome::Success,
            [
                'decisionId' => (string) $decision->id,
                'projectId' => (string) $decision->project->id,
                'cardId' => (string) $cardId,
                'itemId' => null === $decision->item ? null : (string) $decision->item->id,
                'hasNote' => null !== $note,
            ],
            new AuditSubject('inbox_decision', (string) $decision->id),
        );

        return new ChangeInboxDecisionView($decision, $decision->card->column->terminal);
    }
}
