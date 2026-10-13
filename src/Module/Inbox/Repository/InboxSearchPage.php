<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;

/** One page of search hits, best match first. An ask stands for its items, and an item stands alone when no open ask holds it. */
final readonly class InboxSearchPage
{
    /** @param list<InboxAsk|InboxItem> $rows */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
    ) {
    }
}
