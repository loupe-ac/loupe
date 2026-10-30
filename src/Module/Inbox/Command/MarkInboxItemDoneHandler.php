<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxItemCloser;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class MarkInboxItemDoneHandler
{
    /** The done form has no field, so its errors name the item itself. */
    private const string ERROR_FIELD = 'item';

    public const string NOT_A_TO_DO = 'inbox.done.error.not_a_todo';

    public function __construct(
        private InboxItemCloser $closer,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(MarkInboxItemDoneCommand $command): InboxItem
    {
        $item = $command->item;
        if (InboxItemKind::Todo !== $item->kind) {
            throw new DomainErrors([self::ERROR_FIELD => self::NOT_A_TO_DO]);
        }

        $closer = $command->commandSucceeded ? InboxCloser::Loupe : ($command->throughAgent ? InboxCloser::OwnerThroughAgent : InboxCloser::Owner);
        $cause = $command->commandSucceeded ? InboxCloseCause::CommandSucceeded : null;
        $this->closer->respond($item, InboxItemState::Resolved, $closer, $cause, self::ERROR_FIELD, static function (InboxItem $item): ?array {
            $item->note = null;

            return null;
        });

        $this->auditor->record(
            'inbox.item_done',
            AuditOutcome::Success,
            ['itemId' => (string) $item->id, 'projectId' => (string) $item->project->id],
            new AuditSubject('inbox_item', (string) $item->id),
        );

        return $item;
    }
}
