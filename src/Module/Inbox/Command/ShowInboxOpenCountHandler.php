<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Repository\InboxItemRepository;

/** How many rows the open side of the inbox lists, for the sidebar pill. */
final readonly class ShowInboxOpenCountHandler
{
    public function __construct(
        private InboxItemRepository $inboxItems,
    ) {
    }

    public function __invoke(ShowInboxOpenCountCommand $command): int
    {
        return $this->inboxItems->countOpenRowsByProject($command->project);
    }
}
