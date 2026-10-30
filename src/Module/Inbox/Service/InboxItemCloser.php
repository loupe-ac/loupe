<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxItemRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place an item closes with the response of the owner.
 * Every write runs under a lock on the project row. Every writer of items or
 * asks MUST take that lock first, or an ask can close between the check that
 * the item is open and the write that closes it.
 */
final readonly class InboxItemCloser
{
    public const string ERROR_FINAL = 'inbox.item.error.final';
    public const string ERROR_NOTICE = 'inbox.item.error.notice';

    /** The refusal a caller gives when the item it acts on is already closed. */
    public const string ITEM_NOT_OPEN = 'inbox.item.error.not_open';

    public function __construct(
        private EntityManagerInterface $em,
        private InboxItemRepository $inboxItems,
        private InboxAskCloser $askCloser,
        private InboxChangedPublisher $inboxChanged,
        private InboxCardTileRefresher $cardTiles,
    ) {
    }

    /**
     * Applies the owner's $respond to the item and closes it in $state, as
     * $closer and for $cause, in a transaction of its own that locks the project.
     * A response of the owner has no cause.
     *
     * Only an open item takes a response, and a sent response is final. The
     * close closes the asks the item was the last open blocking item of, as the
     * actor of $closer.
     *
     * @param \Closure(InboxItem): (array<string, string>|null) $respond validates against the item as
     *                                                                   stored, then writes the response
     *                                                                   fields; it returns field errors
     *                                                                   to refuse, or null
     *
     * @throws DomainErrors keyed by $errorField, or by the fields $respond names, when the item takes no such response
     */
    public function respond(InboxItem $item, InboxItemState $state, InboxCloser $closer, ?InboxCloseCause $cause, string $errorField, \Closure $respond): void
    {
        self::assertClosed($state);

        $errors = $this->em->wrapInTransaction(function () use ($item, $state, $closer, $cause, $errorField, $respond): ?array {
            $this->em->lock($item->project, LockMode::PESSIMISTIC_WRITE);
            // Read under the lock, so a response or an agent's change that landed
            // since the item was loaded counts. refresh() would also reload
            // readonly columns, which Doctrine refuses to overwrite.
            $this->inboxItems->reloadChangeableColumns([$item]);

            $refusal = self::refusal($item);
            if (null !== $refusal) {
                return [$errorField => $refusal];
            }

            $errors = $respond($item);
            if (null !== $errors && [] !== $errors) {
                return $errors;
            }

            $now = new \DateTimeImmutable();
            $item->closedAt = $now;
            $item->state = $state;
            $item->closedBy = $closer;
            $item->closeCause = $cause;
            $item->updatedAt = $now;
            // The flush that ends the transaction writes only what differs from
            // the copy Doctrine loaded, so it would miss a field cleared back to
            // the value that copy held.
            $this->inboxItems->writeResponse($item);
            $this->askCloser->closeAsksHolding($item, $closer->outboxActor(), $now);

            return null;
        });

        if (null !== $errors) {
            throw new DomainErrors($errors);
        }
        $this->inboxChanged->changed($item->project);
        $this->cardTiles->refresh($item);
    }

    /**
     * The caller holds the project lock and transaction, and flushes afterward.
     */
    public function close(InboxItem $item, InboxItemState $state, InboxCloser $closer, ?InboxCloseCause $cause, ?string $note, \DateTimeImmutable $now): bool
    {
        self::assertClosed($state);
        if (InboxItemState::Open !== $item->state) {
            return false;
        }

        $item->state = $state;
        $item->closedBy = $closer;
        $item->closeCause = $cause;
        $item->note = $note;
        $item->closedAt = $now;
        $item->updatedAt = $now;
        $this->askCloser->closeAsksHolding($item, $closer->outboxActor(), $now);

        return true;
    }

    private static function assertClosed(InboxItemState $state): void
    {
        if (InboxItemState::Open === $state) {
            throw new \LogicException('An item closes into a closed state.');
        }
    }

    /** Why the item takes no response now, or null when it does. A sent response is final. */
    private static function refusal(InboxItem $item): ?string
    {
        if (InboxItemKind::Notice === $item->kind) {
            return self::ERROR_NOTICE;
        }

        return InboxItemState::Open === $item->state ? null : self::ERROR_FINAL;
    }
}
