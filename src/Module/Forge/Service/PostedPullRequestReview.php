<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

/** A review that a forge already holds. */
final readonly class PostedPullRequestReview
{
    public function __construct(
        public ?string $url,
    ) {
    }
}
