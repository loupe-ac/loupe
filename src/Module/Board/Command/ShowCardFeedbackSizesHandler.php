<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;

/** The screenshot size of one feedback note, which its entry draws. */
final readonly class ShowCardFeedbackSizesHandler
{
    public function __construct(private SiteReviewScreenshotRepository $siteReviewScreenshots)
    {
    }

    /**
     * @return array<string, array{width: int, height: int}>
     */
    public function __invoke(CardSiteReviewComment $link): array
    {
        return $this->siteReviewScreenshots->findSizesByComments([$link->comment]);
    }
}
