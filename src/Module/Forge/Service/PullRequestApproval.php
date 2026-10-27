<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

/** The review that a forge created when Loupe approved a pull request. */
final readonly class PullRequestApproval
{
    public function __construct(
        /** The forge's id of the review, the same id that a later state read gives it. */
        public string $reviewId,
        public string $commitSha,
    ) {
    }
}
