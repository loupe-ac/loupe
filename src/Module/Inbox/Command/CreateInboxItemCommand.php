<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Project\Entity\Project;
use App\Security\ApiKeyActor;

/** One item that a caller of the public API hands to the owner. Nothing waits on it. */
final readonly class CreateInboxItemCommand
{
    public function __construct(
        public Project $project,
        public AskInboxItem $item,
        /** Null for a caller with no API key, such as a CLI sign-in, whose item is an agent's. */
        public ?ApiKeyActor $apiKey,
        public ?string $dedupeKey = null,
    ) {
    }
}
