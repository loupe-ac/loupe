<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\CancelBridgeCommandCommand;
use App\Module\Bridge\Command\CancelBridgeCommandHandler;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Withdraws the resume, the agent, the session, the command or the added entry request that an inbox action queued. Only one that
 * still waits on the bridge can be withdrawn. A retry is synchronous, so it never qualifies.
 */
final readonly class CancelInboxActionHandler
{
    public const string NOT_FOUND = 'inbox.action.error.not_found';
    public const string NOT_CANCELLABLE = 'inbox.action.error.not_cancellable';

    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
        private CancelBridgeCommandHandler $cancelBridgeCommand,
        private WithdrawWorkRequestHandler $withdrawWorkRequest,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(CancelInboxActionCommand $command): void
    {
        $action = $command->action;
        $item = $command->item;
        if (null === $item->id || !$item->id->equals($action->item->id)) {
            throw new DomainErrors(['action' => self::NOT_FOUND]);
        }
        if (null !== $action->cancelledAt) {
            throw new DomainErrors(['action' => self::NOT_CANCELLABLE]);
        }

        match ($action->kind) {
            InboxActionKind::ResumeRun => $this->cancelResume($action, $command),
            InboxActionKind::SendAgent, InboxActionKind::StartSession, InboxActionKind::RunCommand, InboxActionKind::AddEntry => $this->cancelWorkRequest($action),
            default => throw new DomainErrors(['action' => self::NOT_CANCELLABLE]),
        };

        $action->cancelledAt = new \DateTimeImmutable();
        $this->em->flush();

        // After the commits, so a refused cancel leaves no record.
        $this->auditor->record(
            'inbox.action_cancelled',
            AuditOutcome::Success,
            [
                'itemId' => (string) $item->id,
                'projectId' => (string) $item->project->id,
                'kind' => $action->kind->value,
                'actionId' => (string) $action->id,
            ],
            new AuditSubject('inbox_item', (string) $item->id),
        );
    }

    private function cancelResume(InboxAction $action, CancelInboxActionCommand $command): void
    {
        $bridgeCommand = null === $action->bridgeCommandId ? null : $this->bridgeCommands->find($action->bridgeCommandId);
        if (null === $bridgeCommand || null === $bridgeCommand->workerRun || BridgeCommandState::Pending !== $bridgeCommand->state) {
            throw new DomainErrors(['action' => self::NOT_CANCELLABLE]);
        }

        try {
            ($this->cancelBridgeCommand)(new CancelBridgeCommandCommand($bridgeCommand->workerRun, $command->user, $bridgeCommand->id));
        } catch (DomainErrors $e) {
            throw new DomainErrors(['action' => array_first($e->errors)]);
        }
    }

    /** The withdraw answers false when a bridge claimed the request since the read, and the claim stands. */
    private function cancelWorkRequest(InboxAction $action): void
    {
        $withdrawn = null !== $action->workRequestId
            && ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($action->workRequestId, WorkRequestState::Cancelled, onlyIfOpen: true));
        if (!$withdrawn) {
            throw new DomainErrors(['action' => self::NOT_CANCELLABLE]);
        }
    }
}
