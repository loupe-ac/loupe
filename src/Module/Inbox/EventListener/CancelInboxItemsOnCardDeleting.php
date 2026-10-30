<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardDeleting;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\CardWaitTrigger;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxCardTileRefresher;
use App\Module\Inbox\Service\InboxChangedPublisher;
use App\Module\Inbox\Service\InboxItemCloser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The links of an item cascade away with the card, so an item that waited
 * only on a deleted card would stay open for good. This cancels it before the
 * remove. An item of an agent that links other cards cancels only when all of
 * them are finished, as it does when a card moves.
 *
 * It runs inside the transaction of the delete, under the project lock, and
 * opens no transaction of its own. The delete flushes it. With the inbox off it
 * closes nothing.
 */
#[AsEventListener]
final readonly class CancelInboxItemsOnCardDeleting
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxAskRepository $inboxAsks,
        private InboxItemCloser $closer,
        private InboxAvailability $inbox,
        private InboxChangedPublisher $inboxChanged,
        private CardWaitTrigger $cardWaits,
        private InboxCardTileRefresher $cardTiles,
    ) {
    }

    public function __invoke(CardDeleting $event): void
    {
        if (!$this->inbox->isEnabled()) {
            return;
        }

        $now = new \DateTimeImmutable();
        $closed = [];
        foreach ($this->inboxItems->findOpenAgentItemsEndingWithCard($event->card) as $item) {
            $cause = $this->linksOnlyTo($event->card, $item) ? InboxCloseCause::CardDeleted : InboxCloseCause::CardFinished;
            if ($this->closer->close($item, InboxItemState::Cancelled, InboxCloser::Loupe, $cause, null, $now)) {
                $closed[] = $item;
                $this->unlink($item, $event->card);
            }
        }

        foreach ($closed as $item) {
            // The database sets the card of an ask to null on the delete. The
            // asks the close just loaded must agree, or a later flush finds them
            // pointing at a removed card.
            foreach ($this->inboxAsks->findHolding($item) as $ask) {
                if ($ask->card === $event->card) {
                    $ask->card = null;
                }
            }
        }

        foreach ($closed as $item) {
            // Signalled before the delete commits, as a card move does. The
            // signal holds no count, so a rollback leaves a pill that reloads.
            $this->inboxChanged->changed($item->project);
        }
        $this->cardTiles->refresh(...$closed);
        $this->cardWaits->forReviewItems($closed);
    }

    /**
     * The database cascades the link, but the item stays loaded. A later flush
     * would find the link pointing at a removed card, so it goes through the ORM too.
     */
    private function unlink(InboxItem $item, Card $card): void
    {
        foreach ($item->cards as $link) {
            if ($link->card === $card) {
                $item->cards->removeElement($link);
            }
        }
    }

    private function linksOnlyTo(Card $card, InboxItem $item): bool
    {
        return $item->cards->forAll(static fn (int $key, InboxItemCard $link): bool => $link->card === $card);
    }
}
