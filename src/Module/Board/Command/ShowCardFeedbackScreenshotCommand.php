<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;

final readonly class ShowCardFeedbackScreenshotCommand
{
    public function __construct(
        public SiteReviewComment $comment,
    ) {
    }
}
