<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Security\ApiKeyActor;

final readonly class AddCommentCommand
{
    public function __construct(
        public User $actor,
        public Document $document,
        public int $displayedVersionNumber,
        public ?string $quote,
        public ?string $prefix,
        public ?string $suffix,
        public string $body,
        // null for a prose comment, '' for a strike, non-empty for a suggested
        // rewording. See Comment::$replacement.
        public ?string $replacement = null,
        // The API key that writes the comment for its owner, who stays the author.
        public ?ApiKeyActor $apiKey = null,
    ) {
    }
}
