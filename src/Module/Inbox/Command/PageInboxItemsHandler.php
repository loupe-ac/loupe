<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxItemRepository;

/**
 * Reads one keyset page of a project's items, newest number first, so an item that closes
 * during a walk never moves it. The search words of a query narrow the walk and keep that order.
 */
final readonly class PageInboxItemsHandler
{
    public const string SORT = 'number';

    public function __construct(
        private InboxItemRepository $inboxItems,
    ) {
    }

    public function __invoke(PageInboxItemsCommand $command): PageInboxItemsView
    {
        $rows = $this->inboxItems->findAfterNumber(
            $command->project,
            $command->state,
            $command->askId,
            $command->sessionId,
            $command->cardId,
            $command->documentId,
            $command->query,
            $command->page->cursor?->numberPosition(),
            $command->page->fetchSize(),
        );

        return new PageInboxItemsView(Page::fromRows($rows, $command->page, static fn (InboxItem $item): Cursor => Cursor::afterNumber(self::SORT, $item->number, $command->filters)));
    }
}
