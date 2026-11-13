<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Security\ApiKeyActor;

final readonly class DeclineInboxItemCommand
{
    public function __construct(
        public InboxItem $item,
        public string $note,
        public InboxCloser $closedBy = InboxCloser::Owner,
        public ?ApiKeyActor $apiKey = null,
    ) {
    }
}
