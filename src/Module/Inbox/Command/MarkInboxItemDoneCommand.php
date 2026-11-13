<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Security\ApiKeyActor;

final readonly class MarkInboxItemDoneCommand
{
    public function __construct(
        public InboxItem $item,
        public InboxCloser $closedBy = InboxCloser::Owner,
        /** A run-command the owner started from the inbox succeeded, so Loupe closes the to-do. */
        public bool $commandSucceeded = false,
        /** The optional note the owner sends with the completion. */
        public string $note = '',
        public ?ApiKeyActor $apiKey = null,
    ) {
    }
}
