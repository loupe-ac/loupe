<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\CancelBridgeCommandCommand;
use App\Module\Bridge\Command\CancelBridgeCommandHandler;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Inbox\Entity\InboxActionKind;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Withdraws the resume that an inbox action queued. Only a resume that still waits on the bridge
 * can be withdrawn. A retry is synchronous, so it never qualifies.
 */
final readonly class CancelInboxActionHandler
{
    public const string NOT_FOUND = 'inbox.action.error.not_found';
    public const string NOT_CANCELLABLE = 'inbox.action.error.not_cancellable';

    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
        private CancelBridgeCommandHandler $cancelBridgeCommand,
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
        $bridgeCommand = null === $action->bridgeCommandId ? null : $this->bridgeCommands->find($action->bridgeCommandId);
        if (
            InboxActionKind::ResumeRun !== $action->kind
            || null !== $action->cancelledAt
            || null === $bridgeCommand
            || BridgeCommandState::Pending !== $bridgeCommand->state
        ) {
            throw new DomainErrors(['action' => self::NOT_CANCELLABLE]);
        }

        try {
            ($this->cancelBridgeCommand)(new CancelBridgeCommandCommand($bridgeCommand->workerRun, $command->user, $bridgeCommand->id));
        } catch (DomainErrors $e) {
            throw new DomainErrors(['action' => array_first($e->errors)]);
        }

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
}
