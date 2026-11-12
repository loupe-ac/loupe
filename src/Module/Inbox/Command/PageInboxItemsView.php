<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Api\Pagination\Page;
use App\Module\Inbox\Entity\InboxItem;

/** One page of items, with the cursor of the next page. */
final readonly class PageInboxItemsView
{
    /** @param Page<InboxItem> $page */
    public function __construct(
        public Page $page,
    ) {
    }
}
