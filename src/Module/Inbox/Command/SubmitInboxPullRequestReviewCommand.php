<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Security\ApiKeyActor;

final readonly class SubmitInboxPullRequestReviewCommand
{
    public function __construct(
        public InboxItem $item,
        public User $reviewer,
        public string $verdict,
        public string $expectedUrl,
        public ?string $note = null,
        public InboxCloser $closedBy = InboxCloser::Owner,
        public ?ApiKeyActor $apiKey = null,
    ) {
    }
}
