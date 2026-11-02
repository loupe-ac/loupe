<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Comment;
use App\Security\ApiKeyActor;

final readonly class ReplyToCommentCommand
{
    public function __construct(
        public User $actor,
        public Comment $parent,
        public string $body,
        // The API key that writes the reply for its owner, who stays the author.
        public ?ApiKeyActor $apiKey = null,
    ) {
    }
}
