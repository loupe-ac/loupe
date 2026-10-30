<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Uid\Uuid;

final readonly class WithdrawInboxItemCommand
{
    public function __construct(
        public InboxItem $item,
        /** Why the agent no longer needs the item. The handler refuses a blank one. */
        public string $reason,
        /** The session that withdraws. It must be the session of the first ask that holds the item. */
        public Uuid $sessionId,
    ) {
    }
}
