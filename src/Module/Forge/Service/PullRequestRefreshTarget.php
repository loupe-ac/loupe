<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\PullRequestReview;

/** Names the pull requests one fact can change: by number, by head commit or by base branch. Exactly one of the three is set. */
final readonly class PullRequestRefreshTarget
{
    private function __construct(
        public ?int $number = null,
        public ?string $headSha = null,
        public ?string $baseBranch = null,
        public ?PullRequestReview $verdict = null,
        public ?string $reviewId = null,
    ) {
    }

    public static function number(int $number, ?PullRequestReview $verdict = null, ?string $reviewId = null): self
    {
        return new self(number: $number, verdict: $verdict, reviewId: $reviewId);
    }

    public static function head(string $sha): self
    {
        return new self(headSha: $sha);
    }

    public static function base(string $branch): self
    {
        return new self(baseBranch: $branch);
    }
}
