<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;

/** Reads one item of the project with its asks, or null when the project holds no item with this id. */
final readonly class ShowProjectInboxItemHandler
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxAskRepository $inboxAsks,
    ) {
    }

    public function __invoke(ShowProjectInboxItemCommand $command): ?InboxItemDetailView
    {
        $item = $this->inboxItems->findOneByIdAndProjectId($command->itemId, (string) ($command->project->id ?? throw new \LogicException('A stored project has an id.')));

        return null === $item ? null : new InboxItemDetailView($item, $this->inboxAsks->findHolding($item));
    }
}
