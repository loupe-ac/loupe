<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewComment;

final readonly class ShowFeedbackView
{
    /** @param ?array{width: int, height: int} $screenshot */
    public function __construct(
        public SiteReviewComment $feedback,
        public ?CardSiteReviewComment $link,
        public ?array $screenshot,
    ) {
    }
}
