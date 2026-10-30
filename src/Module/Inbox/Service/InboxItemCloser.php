<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place an item closes, or an owner changes a response already given.
 * Every write runs under a lock on the project row. Every writer of items or
 * asks MUST take that lock first, or an ask can close between the check that an
 * answer is still editable and the write that changes it.
 */
final readonly class InboxItemCloser
{
    public const string ERROR_FINAL = 'inbox.item.error.final';
    public const string ERROR_CLOSED_BY_AGENT = 'inbox.item.error.closed_by_agent';
    public const string ERROR_CLOSED_BY_LOUPE = 'inbox.item.error.closed_by_loupe';
    public const string ERROR_NOTICE = 'inbox.item.error.notice';

    /** The refusal a caller gives when the item it acts on is already closed. */
    public const string ITEM_NOT_OPEN = 'inbox.item.error.not_open';

    public function __construct(
        private EntityManagerInterface $em,
        private InboxAskRepository $inboxAsks,
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
     * An open item always takes a response. A closed one takes a change only
     * while no closed ask holds it, because an agent may already act on the
     * answer that closed that ask. The close of an open item closes the asks
     * it was the last open blocking item of, as the actor of $closer.
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

        $outcome = $this->em->wrapInTransaction(function () use ($item, $state, $closer, $cause, $errorField, $respond): array|bool {
            $this->em->lock($item->project, LockMode::PESSIMISTIC_WRITE);
            // Read under the lock, so a response or an agent's change that landed
            // since the item was loaded counts. refresh() would also reload
            // readonly columns, which Doctrine refuses to overwrite.
            $this->inboxItems->reloadChangeableColumns([$item]);

            $refusal = $this->refusal($item);
            if (null !== $refusal) {
                return [$errorField => $refusal];
            }

            $wasOpen = InboxItemState::Open === $item->state;
            $errors = $respond($item);
            if (null !== $errors && [] !== $errors) {
                return $errors;
            }

            $now = new \DateTimeImmutable();
            if ($wasOpen) {
                $item->closedAt = $now;
            }
            $item->state = $state;
            $item->closedBy = $closer;
            $item->closeCause = $cause;
            $item->updatedAt = $now;
            // The flush that ends the transaction writes only what differs from
            // the copy Doctrine loaded, so it would miss a field cleared back to
            // the value that copy held.
            $this->inboxItems->writeResponse($item);
            if ($wasOpen) {
                $this->askCloser->closeAsksHolding($item, $closer->outboxActor(), $now);
            }

            return $wasOpen;
        });

        if (\is_array($outcome)) {
            throw new DomainErrors($outcome);
        }
        if ($outcome) {
            $this->inboxChanged->changed($item->project);
            $this->cardTiles->refresh($item);
        } else {
            $this->inboxChanged->pageChanged($item->project);
        }
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

    /** Why the item takes no response now, or null when it does. */
    private function refusal(InboxItem $item): ?string
    {
        if (InboxItemKind::Notice === $item->kind) {
            return self::ERROR_NOTICE;
        }
        if ((InboxItemKind::Workflow === $item->kind || (InboxItemKind::Review === $item->kind && !$item->isAutomatic())) && InboxItemState::Open !== $item->state) {
            return self::ERROR_FINAL;
        }
        if (InboxAskOrigin::Loupe === $item->origin && \in_array($item->state, [InboxItemState::Resolved, InboxItemState::Cancelled], true)) {
            return self::ERROR_CLOSED_BY_LOUPE;
        }

        // The two calls that ignore the asks settle an open item and an agent's
        // close with no query. Only a response the owner gave needs the asks.
        if ($item->state->acceptsResponse(heldByClosedAsk: true)) {
            return null;
        }
        if (!$item->state->acceptsResponse(heldByClosedAsk: false)) {
            return self::ERROR_CLOSED_BY_AGENT;
        }

        return $item->state->acceptsResponse([] !== $this->inboxAsks->findItemIdsHeldByClosedAsks([$item])) ? null : self::ERROR_FINAL;
    }
}
