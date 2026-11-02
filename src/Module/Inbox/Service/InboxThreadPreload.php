<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\View\InboxActionStatus;
use App\Module\Inbox\View\ItemAction;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What the threads of a page need for every item it shows, read once per request: the watches, the actions
 * that fit each item and the status of each action that ran. Only a page handler preloads. An item it holds
 * nothing for gets null, and the caller reads that item as before.
 */
final class InboxThreadPreload implements ResetInterface
{
    /** @var array<string, list<ItemAction>> */
    private array $actions = [];

    /** @var array<string, list<InboxActionStatus>> */
    private array $statuses = [];

    public function __construct(
        private readonly InboxItemRepository $inboxItems,
        private readonly InboxWatchLookup $watches,
        private readonly ItemActions $itemActions,
        private readonly InboxActionStatuses $actionStatuses,
        private readonly InboxWaitSubjects $waitSubjects,
    ) {
    }

    /** @param list<InboxItem> $items */
    public function preload(array $items): void
    {
        if ([] === $items) {
            return;
        }

        $this->inboxItems->loadDocuments($items);
        $this->watches->preload($items);
        $this->waitSubjects->preload(array_merge(...array_map(
            static fn (InboxCardWatch $watch): array => array_values($watch->waits->toArray()),
            $this->watches->forItems($items),
        )));
        $this->actions = [...$this->actions, ...$this->itemActions->forItems($items)];
        $this->statuses = [...$this->statuses, ...$this->actionStatuses->forItems($items)];
        foreach ($items as $item) {
            $this->actions[(string) $item->id] ??= [];
            $this->statuses[(string) $item->id] ??= [];
        }
    }

    /** @return ?list<ItemAction> null when the item was not preloaded */
    public function actionsOf(InboxItem $item): ?array
    {
        return $this->actions[(string) $item->id] ?? null;
    }

    /** @return ?list<InboxActionStatus> null when the item was not preloaded */
    public function statusesOf(InboxItem $item): ?array
    {
        return $this->statuses[(string) $item->id] ?? null;
    }

    #[\Override]
    public function reset(): void
    {
        $this->actions = [];
        $this->statuses = [];
    }
}
