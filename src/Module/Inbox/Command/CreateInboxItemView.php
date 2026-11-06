<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;

/** The item a create made, or the open item that already held its dedupe key. */
final readonly class CreateInboxItemView
{
    public function __construct(
        public InboxItem $item,
        public bool $created,
    ) {
    }
}
