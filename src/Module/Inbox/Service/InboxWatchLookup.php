<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The watch behind a wait item. A page handler preloads the watches of every item the page shows in one
 * query, and each thread and action reads from that. It holds only what a preload put in it, so a worker
 * and a write path always read fresh rows. An item it was not asked about is queried and not kept.
 */
final class InboxWatchLookup implements ResetInterface
{
    /** @var array<string, ?InboxCardWatch> */
    private array $byItem = [];

    public function __construct(
        private readonly InboxCardWatchRepository $inboxCardWatches,
    ) {
    }

    /** @param list<InboxItem> $items */
    public function preload(array $items): void
    {
        $automatic = array_values(array_filter($items, static fn (InboxItem $item): bool => $item->isAutomatic()));
        foreach ($this->inboxCardWatches->findForItems($automatic) as $watch) {
            $this->byItem[(string) $watch->item->id] = $watch;
        }
        foreach ($automatic as $item) {
            $this->byItem[(string) $item->id] ??= null;
        }
    }

    /**
     * The watches of the items that have one, from the preload when it covers them all.
     *
     * @param list<InboxItem> $items
     *
     * @return list<InboxCardWatch>
     */
    public function forItems(array $items): array
    {
        $automatic = array_values(array_filter($items, static fn (InboxItem $item): bool => $item->isAutomatic()));
        if (array_all($automatic, fn (InboxItem $item): bool => array_key_exists((string) $item->id, $this->byItem))) {
            return array_values(array_filter(array_map(fn (InboxItem $item): ?InboxCardWatch => $this->byItem[(string) $item->id], $automatic)));
        }

        return $this->inboxCardWatches->findForItems($automatic);
    }

    public function forItem(InboxItem $item): ?InboxCardWatch
    {
        if (!$item->isAutomatic()) {
            return null;
        }
        $key = (string) $item->id;

        return array_key_exists($key, $this->byItem) ? $this->byItem[$key] : $this->inboxCardWatches->findOneForItem($item);
    }

    #[\Override]
    public function reset(): void
    {
        $this->byItem = [];
    }
}
