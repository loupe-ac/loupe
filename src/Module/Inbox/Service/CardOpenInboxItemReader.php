<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Service\CardOpenInboxItems;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** The inbox page answers 404 while the inbox is off, so the board then links no item. */
#[AsAlias(CardOpenInboxItems::class)]
final readonly class CardOpenInboxItemReader implements CardOpenInboxItems
{
    public function __construct(
        private InboxAvailability $inbox,
        private InboxItemRepository $inboxItems,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): array
    {
        return $this->inbox->isEnabled() ? $this->inboxItems->findOldestOpenNumberPerCard($project) : [];
    }
}
