<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;

final readonly class MarkInboxItemDoneCommand
{
    public function __construct(
        public InboxItem $item,
        public bool $throughAgent = false,
        /** A run-command the owner started from the inbox succeeded, so Loupe closes the to-do. */
        public bool $commandSucceeded = false,
        /** The optional note the owner sends with the completion. */
        public string $note = '',
    ) {
    }
}
