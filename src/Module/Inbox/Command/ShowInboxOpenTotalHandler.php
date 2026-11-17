<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Repository\InboxItemRepository;

/** How many open rows the inboxes list over every project of the user, for the tab title. */
final readonly class ShowInboxOpenTotalHandler
{
    public function __construct(
        private InboxItemRepository $inboxItems,
    ) {
    }

    public function __invoke(ShowInboxOpenTotalCommand $command): int
    {
        return $this->inboxItems->countOpenRowsOfOwner($command->user);
    }
}
