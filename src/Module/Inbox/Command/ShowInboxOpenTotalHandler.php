<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Repository\ProjectRepository;

/** How many items are open over every project of the user, for the tab title. */
final readonly class ShowInboxOpenTotalHandler
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private ProjectRepository $projects,
    ) {
    }

    public function __invoke(ShowInboxOpenTotalCommand $command): int
    {
        return array_sum($this->inboxItems->countOpenByProjects($this->projects->findByOwner($command->user)));
    }
}
