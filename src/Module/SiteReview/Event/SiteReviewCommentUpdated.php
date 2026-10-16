<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Event;

use Symfony\Component\Uid\Uuid;

/** A pending comment got a new body, after the change was saved. A listener must never throw. */
final readonly class SiteReviewCommentUpdated
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $commentId,
        public string $body,
    ) {
    }
}
