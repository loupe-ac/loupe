<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;

/**
 * One row of the list: an ask, or an open item that no open ask holds.
 *
 * The ask has no title of its own, so its first item names it. The icon comes
 * from the first open item of an open ask, and from the first item of a closed one.
 */
final readonly class InboxRow
{
    public InboxItem $leading;

    public bool $blocking;

    /** @var list<Card> the cards the items link, each once, in link order */
    public array $cards;

    /**
     * @param list<InboxItem>    $items   at least one, in the order the ask holds them
     * @param ?InboxOutcomeLabel $outcome how a closed row ended
     * @param ?InboxItem         $closing the item that closed the row, which also gives its outcome
     */
    public function __construct(
        public ?InboxAsk $ask,
        public array $items,
        public ?InboxOutcomeLabel $outcome = null,
        public ?InboxItem $closing = null,
    ) {
        $first = $items[0] ?? throw new \LogicException('A row holds at least one item.');
        $open = array_find($items, static fn (InboxItem $item): bool => InboxItemState::Open === $item->state);

        $this->leading = null !== $ask?->closedAt ? $first : ($open ?? $first);
        $this->blocking = null !== array_find($items, static fn (InboxItem $item): bool => $item->blocking && InboxItemState::Open === $item->state);

        $cards = [];
        foreach ($items as $item) {
            foreach ($item->cards as $link) {
                $cards[(string) $link->card->id] ??= $link->card;
            }
        }
        $this->cards = array_values($cards);
    }

    public function key(): string
    {
        return null === $this->ask ? 'item-'.$this->items[0]->number : (string) $this->ask->id;
    }

    public function title(): string
    {
        return $this->items[0]->title;
    }

    /** The title cut for a list row. The row shows the full title in a tooltip. */
    public function shortTitle(int $maxLength = 80): string
    {
        $title = $this->title();

        return mb_strlen($title) > $maxLength ? rtrim(mb_substr($title, 0, $maxLength - 1)).'…' : $title;
    }

    public function kind(): InboxItemKind
    {
        return $this->leading->kind;
    }

    public function origin(): InboxAskOrigin
    {
        return $this->items[0]->origin;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->ask->createdAt ?? $this->items[0]->createdAt;
    }

    /** Whether any item of the row has the kind. */
    public function holdsKind(InboxItemKind $kind): bool
    {
        return null !== array_find($this->items, static fn (InboxItem $item): bool => $item->kind === $kind);
    }

    public function isClosed(): bool
    {
        return null === $this->ask ? InboxItemState::Open !== $this->items[0]->state : null !== $this->ask->closedAt;
    }

    public function isLoose(): bool
    {
        return null === $this->ask;
    }

    public function firstCard(): ?Card
    {
        return $this->cards[0] ?? null;
    }

    /** The first card of the first open blocking item that links one: the card that waits on the owner. */
    public function blockingCard(): ?Card
    {
        foreach ($this->items as $item) {
            $link = $item->cards->first();
            if ($item->blocking && InboxItemState::Open === $item->state && false !== $link) {
                return $link->card;
            }
        }

        return null;
    }

    public function moreCards(): int
    {
        return max(0, \count($this->cards) - 1);
    }

    /**
     * The view that selects this row and keeps the rest of the address.
     */
    public function pickedIn(InboxView $view): InboxView
    {
        return null === $this->ask || null === $this->ask->id ? $view->withItem($this->items[0]->number) : $view->withAsk($this->ask->id);
    }
}
