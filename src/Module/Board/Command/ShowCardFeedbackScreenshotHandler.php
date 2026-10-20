<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;

final readonly class ShowCardFeedbackScreenshotHandler
{
    public function __construct(
        private SiteReviewScreenshotRepository $siteReviewScreenshots,
    ) {
    }

    /** Null when the note has no screenshot. */
    public function __invoke(ShowCardFeedbackScreenshotCommand $command): ?FeedbackScreenshotImage
    {
        $screenshot = $this->siteReviewScreenshots->findOneByComment($command->comment);

        return null === $screenshot ? null : new FeedbackScreenshotImage($screenshot->contents(), $screenshot->contentType);
    }
}
