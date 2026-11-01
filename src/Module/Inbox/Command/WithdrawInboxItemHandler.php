<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\InboxLimits;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\CardWaitTrigger;
use App\Module\Inbox\Service\InboxCardTileRefresher;
use App\Module\Inbox\Service\InboxChangedPublisher;
use App\Module\Inbox\Service\InboxItemCloser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Closes an open item as withdrawn, with the reason in its note. */
final readonly class WithdrawInboxItemHandler
{
    public const string REASON_BLANK = 'inbox.item.error.withdraw_reason_blank';
    public const string REASON_TOO_LONG = 'inbox.item.error.withdraw_reason_too_long';
    public const string WAIT_NOT_WITHDRAWABLE = 'inbox.item.error.wait_not_withdrawable';
    public const string NOTICE_NOT_WITHDRAWABLE = 'inbox.item.error.notice_not_withdrawable';
    public const string WORKFLOW_NOT_WITHDRAWABLE = 'inbox.item.error.workflow_not_withdrawable';
    public const string NOT_ASKING_SESSION = 'inbox.item.error.withdraw_not_asking_session';

    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxAskRepository $inboxAsks,
        private InboxItemCloser $closer,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private InboxChangedPublisher $inboxChanged,
        private CardWaitTrigger $cardWaits,
        private InboxCardTileRefresher $cardTiles,
    ) {
    }

    public function __invoke(WithdrawInboxItemCommand $command): InboxItem
    {
        // Loupe opened the item and closes it when the card stops waiting.
        if ($command->item->isAutomatic()) {
            throw new DomainErrors(['itemId' => self::WAIT_NOT_WITHDRAWABLE]);
        }
        if (InboxItemKind::Notice === $command->item->kind) {
            throw new DomainErrors(['itemId' => self::NOTICE_NOT_WITHDRAWABLE]);
        }
        if (InboxItemKind::Workflow === $command->item->kind) {
            throw new DomainErrors(['itemId' => self::WORKFLOW_NOT_WITHDRAWABLE]);
        }
        // Only the session that asked first can take the item back.
        $firstAsk = $this->inboxAsks->findHolding($command->item)[0] ?? null;
        if (null === $firstAsk?->sessionId || !$firstAsk->sessionId->equals($command->sessionId)) {
            throw new DomainErrors(['sessionId' => self::NOT_ASKING_SESSION]);
        }
        if (mb_strlen($command->reason) > InboxLimits::MAX_WITHDRAW_REASON_LENGTH) {
            throw new DomainErrors(['reason' => self::REASON_TOO_LONG]);
        }
        $reason = trim($command->reason);
        if ('' === $reason) {
            throw new DomainErrors(['reason' => self::REASON_BLANK]);
        }

        $item = $command->item;
        $refusal = $this->em->wrapInTransaction(function () use ($item, $reason): ?string {
            // The project first, as every item writer takes it, then the state as
            // stored, so an answer that lands first is not overwritten.
            $this->em->lock($item->project, LockMode::PESSIMISTIC_WRITE);
            if (InboxItemState::Open !== $this->inboxItems->lockedState($item)
                || !$this->closer->close($item, InboxItemState::Cancelled, InboxCloser::Agent, InboxCloseCause::Withdrawn, $reason, new \DateTimeImmutable())) {
                return InboxItemCloser::ITEM_NOT_OPEN;
            }
            $this->em->flush();

            return null;
        });

        if (null !== $refusal) {
            throw new DomainErrors(['itemId' => $refusal]);
        }
        $this->inboxChanged->changed($item->project);
        $this->cardTiles->refresh($item);
        $this->cardWaits->forReviewItems([$item]);

        $this->auditor->record(
            'inbox.item_withdrawn',
            AuditOutcome::Success,
            [
                'itemId' => (string) $item->id,
                'itemNumber' => $item->number,
                'projectId' => (string) $item->project->id,
            ],
            new AuditSubject('inbox_item', (string) $item->id),
        );

        return $item;
    }
}
