<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Api\Pagination\PageRequest;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * One keyset page of a project's items. Every filter is optional, and null means "any".
 * $filters repeats them in the form a cursor holds, so the next cursor refuses a walk whose filters change.
 */
final readonly class PageInboxItemsCommand
{
    /** @param array<string, scalar|null> $filters */
    public function __construct(
        public Project $project,
        public PageRequest $page,
        public array $filters,
        public ?InboxItemState $state = null,
        public ?Uuid $askId = null,
        public ?Uuid $sessionId = null,
        public ?Uuid $cardId = null,
        public ?Uuid $documentId = null,
        /** Search words. They narrow the walk and do not rank it. */
        public ?string $query = null,
    ) {
    }
}
